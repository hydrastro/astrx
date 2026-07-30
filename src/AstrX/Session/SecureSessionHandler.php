<?php
declare(strict_types=1);

namespace AstrX\Session;

use PDO;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;
use AstrX\Config\InjectConfig;

/**
 * Database-backed, optionally encrypted session handler.
 *
 * Encryption scheme: AES-256-CTR with a per-session HMAC-SHA256 authentication
 * tag prepended to the ciphertext so tampering is detected on read.
 *
 * Table schema expected:
 *   CREATE TABLE `session` (
 *       `id`          VARCHAR(128) NOT NULL PRIMARY KEY,
 *       `timestamp`   INT UNSIGNED NOT NULL,
 *       `data`        MEDIUMBLOB   NOT NULL DEFAULT '',
 *       `replaced_by` CHAR(128)    NULL DEFAULT NULL,
 *       `replace_at`  INT UNSIGNED NULL DEFAULT NULL
 *   );
 *
 * replaced_by = hashed ID of the successor session (set on regeneration).
 * replace_at  = Unix timestamp when regeneration occurred.
 * Both columns support the grace-period handover window.
 */
final class SecureSessionHandler implements
    SessionHandlerInterface,
    SessionIdInterface,
    SessionUpdateTimestampHandlerInterface
{
    private int $sidBytes = 128;
    private bool $encrypt = true;
    private int $maxRetries = 10;
    /** Server-side secret mixed into encryption key so stolen DB rows cannot be
     *  decrypted without also knowing the application secret. */
    private string $serverSecret = '';

    /**
     * The server_secret value that was, for a time, committed to the public
     * repository in Session.config.php. It is therefore no longer secret. If an
     * install still carries this exact value (e.g. copied from an old checkout),
     * we IGNORE it and fall through to the per-install generated fallback so a
     * site can never run on a globally-known key.
     */
    private const LEAKED_SERVER_SECRET =
        '2cadc3c3e1e0509c705e758f02e9d39c27446c2a509bc828b5ddbd6af4026ec4';

    /** Holds the freshly generated SID so validateId() can confirm it in-process. */
    private ?string $currentSessionId = null;

    /** Memoised HKDF input key material — resolved once per request (per handler
     *  instance) so every ikm() call agrees and the fallback file is touched once. */
    private ?string $ikmCache = null;

    /** Seconds an old (rotated-away) session row stays valid after regeneration.
     *  Configurable via Session.regenerate_grace_period; enforced in read(). */
    private int $graceSeconds = 30;

    public function __construct(private readonly PDO $pdo) {}

    #[InjectConfig('sid_bytes')]
    public function setSidBytes(int $sidBytes): void
    {
        $this->sidBytes = $sidBytes;
    }

    #[InjectConfig('encrypt')]
    public function setEncrypt(bool $encrypt): void
    {
        $this->encrypt = $encrypt;
    }

    #[InjectConfig('max_sid_retries')]
    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = max(1, $maxRetries);
    }

    #[InjectConfig('regenerate_grace_period')]
    public function setGraceSeconds(int $seconds): void
    {
        $this->graceSeconds = max(0, $seconds);
    }

    #[InjectConfig('server_secret')]
    public function setServerSecret(string $secret): void
    {
        $this->serverSecret = $secret;
    }

    /**
     * Returns the effective IKM for HKDF.
     * hash_hkdf() requires a non-empty key; if server_secret is not configured
     * we fall back to a fixed string derived from a constant phrase so the
     * system remains functional. Sessions are still AES-256 encrypted; they
     * just lack the additional server-secret protection against DB-only attacks.
     * Set 'server_secret' in Session.config.php for full security.
     */
    private function ikm(): string
    {
        // Resolve once per request. Without this, and with no writable fallback
        // path, each call below would generate a DIFFERENT random key — so a blob
        // encrypted earlier in the request could not be decrypted later in it.
        if ($this->ikmCache !== null) {
            return $this->ikmCache;
        }

        // 1. Explicit admin-configured secret (recommended). The old leaked/
        //    committed value is ignored — treated as unset — so no site ever runs
        //    on a globally-known key. hash_equals for constant-time comparison.
        if ($this->serverSecret !== ''
            && !hash_equals(self::LEAKED_SERVER_SECRET, $this->serverSecret)
        ) {
            return $this->ikmCache = $this->serverSecret;
        }

        // 2. No configured server_secret → a lazily-generated per-installation
        //    fallback secret, unique to this install. Candidate paths are ordered
        //    most-durable first (config dir) down to most-reliably-writable (temp
        //    dir). This matters: under Docker the bind-mounted config dir is often
        //    NOT writable by the php-fpm user (www-data), and if the secret cannot
        //    persist, ikm() differs on every request — every session decrypts to
        //    empty and every POST (login included) becomes a 400. The temp-dir
        //    fallback keeps the key stable at least until the container is
        //    recreated. Setting server_secret explicitly avoids all of this.
        $configDir  = \AstrX\Support\configDir();
        $candidates = array_values(array_filter([
            $configDir !== '' ? $configDir . '.server_secret_generated' : null,
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'astrx_server_secret',
        ]));

        // Read an already-persisted secret from the first candidate that has one.
        $tmpDir = sys_get_temp_dir();
        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }
            // Harden the world-writable temp fallback: only trust it when it is
            // NOT group/world-accessible, so a local user cannot pre-seed a known
            // secret at the predictable sys_get_temp_dir() path and downgrade our
            // session key. The app-private config-dir candidate is trusted as-is
            // (its exact owner may legitimately differ, e.g. installer vs runtime).
            if ($tmpDir !== '' && str_starts_with($file, $tmpDir)) {
                $perms = @fileperms($file);
                if ($perms === false || ($perms & 0077) !== 0) {
                    continue;
                }
            }
            $contents = @file_get_contents($file);
            if (is_string($contents) && $contents !== '') {
                return $this->ikmCache = $contents;
            }
        }

        // First run — generate once and persist to the first WRITABLE candidate.
        $generated = random_bytes(32);
        foreach ($candidates as $file) {
            if (@file_put_contents($file, $generated, LOCK_EX) !== false) {
                @chmod($file, 0600);
                return $this->ikmCache = $generated;
            }
        }

        // 3. No writable location at all (very rare). Cache the in-memory value so
        //    all calls WITHIN this request still agree; cross-request stability then
        //    requires setting 'server_secret' in Session.config.php.
        return $this->ikmCache = $generated;
    }

    // -------------------------------------------------------------------------
    // SessionHandlerInterface
    // -------------------------------------------------------------------------

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM `session` WHERE `id` = :id');
        $stmt->execute(['id' => $this->hashId($id)]);
        return true;
    }

    public function gc(int $maxLifetime): int
    {
        $cutoff = time() - $maxLifetime;

        $stmt = $this->pdo->prepare(
            'DELETE FROM `session` WHERE `timestamp` < :cutoff'
        );
        $stmt->execute(['cutoff' => $cutoff]);
        $deleted = $stmt->rowCount();

        // Also null out expired handover pointers so the columns don't grow stale.
        // We keep the row alive (it may still hold session data); we just clear the
        // replaced_by pointer once the grace period has elapsed for that row.
        // Wrapped so a `session` table WITHOUT the optional handover columns
        // (a legacy install that hasn't migrated) doesn't throw an uncaught
        // PDOException when GC fires — the row DELETE above already ran and is
        // what matters; the pointer cleanup is a no-op when the columns are absent.
        try {
            $graceCutoff = time() - $this->graceSeconds; // configurable via Session.regenerate_grace_period
            $stmt2 = $this->pdo->prepare(
                'UPDATE `session` SET `replaced_by` = NULL, `replace_at` = NULL
                  WHERE `replace_at` IS NOT NULL AND `replace_at` < :gc'
            );
            $stmt2->execute([':gc' => $graceCutoff]);
        } catch (\PDOException) {
            // Legacy schema without handover columns — nothing to clean up.
        }

        return $deleted; // PDO::rowCount() after DELETE is reliable on MySQL/MariaDB
    }

    public function read(string $id): string
    {
        $row = $this->readRow($this->hashId($id));

        // ── Handover lookup (grace-period support) ────────────────────────
        // If the session row is missing, check whether it was recently replaced
        // (i.e. the session ID was regenerated and the old row now has a
        // replaced_by pointer).  Follow at most one hop to avoid infinite loops.
        if ($row === false) {
            return '';
        }

        // If this row has been replaced and we are within the grace period,
        // transparently redirect to the successor session.
        $replacedBy = isset($row['replaced_by']) && is_string($row['replaced_by'])
            ? $row['replaced_by'] : null;
        $replaceAt  = isset($row['replace_at'])  && is_int($row['replace_at'])
            ? $row['replace_at'] : null;

        // Grace-period EXPIRY (security): once an old (rotated-away) row is past
        // the grace window, the old session id MUST stop working. Its timestamp
        // is refreshed on every use, so gc() (delete-by-inactivity) would never
        // collect it — a captured/rotated-away id would otherwise stay valid
        // forever, defeating rotation. The legit client already moved to the
        // successor id via Set-Cookie, so only stale/in-flight or stolen requests
        // still carry the old id here.
        if ($replaceAt !== null && (time() - $replaceAt) > $this->graceSeconds) {
            $this->destroy($id);
            return '';
        }

        if ($replacedBy !== null && $replaceAt !== null && !$this->encrypt) {
            // Grace-period handover: a request still carrying the OLD id is served
            // the successor row. This is only sound when sessions are NOT
            // encrypted — the successor's data is encrypted under the NEW raw
            // session id, and replaced_by holds only its SHA-512 hash, so we
            // cannot derive the key to decrypt it here (doing so returned an empty
            // session, silently logging out in-flight requests during regen). In
            // encrypted mode the OLD row's OWN data is already the current
            // post-regeneration snapshot (session_regenerate_id copies $_SESSION)
            // and IS decryptable with the old id, so we fall through to serve it.
            $successor = $this->readRow($replacedBy);
            if ($successor !== false) {
                $row = $successor;
            }
        }

        if (!$this->encrypt) {
            $dataVal = $row['data'] ?? '';
            return is_scalar($dataVal) ? (string)$dataVal : '';
        }

        $dataVal2 = $row['data'] ?? '';
        return $this->decrypt($id, is_scalar($dataVal2) ? (string)$dataVal2 : '');
    }

    public function write(string $id, string $data): bool
    {
        $hashedId = $this->hashId($id);
        $payload  = $this->encrypt ? $this->encrypt($id, $data) : $data;
        $ts       = time();

        // UPSERT — single atomic statement avoids the read-then-write race
        // where two concurrent requests could both read 'row not found' and
        // both attempt INSERT, causing one to fail.
        $stmt = $this->pdo->prepare(
            'INSERT INTO `session` (`id`, `timestamp`, `data`) VALUES (:id, :ts, :data)'
            . ' ON DUPLICATE KEY UPDATE `data` = VALUES(`data`), `timestamp` = VALUES(`timestamp`)'
        );
        $stmt->execute(['id' => $hashedId, 'ts' => $ts, 'data' => $payload]);
        return true;
    }

    // -------------------------------------------------------------------------
    // SessionIdInterface
    // -------------------------------------------------------------------------

    public function create_sid(): string
    {
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            $sid    = bin2hex(random_bytes(max(1, $this->sidBytes)));
            $hashed = $this->hashId($sid);

            $stmt = $this->pdo->prepare('SELECT 1 FROM `session` WHERE `id` = :id');
            $stmt->execute(['id' => $hashed]);

            if ($stmt->fetch() === false) {
                $this->currentSessionId = $sid;
                return $sid;
            }
        }

        throw new \RuntimeException(
            sprintf('Failed to generate a unique session ID after %d attempts.', $this->maxRetries)
        );
    }

    // -------------------------------------------------------------------------
    // SessionUpdateTimestampHandlerInterface
    // -------------------------------------------------------------------------

    public function validateId(string $id): bool
    {
        if ($this->currentSessionId !== null && $id === $this->currentSessionId) {
            return true;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM `session` WHERE `id` = :id');
        $stmt->execute(['id' => $this->hashId($id)]);
        return $stmt->fetch() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return array<string,mixed>|false */
    private function readRow(string $hashedId): array|false
    {
        // SELECT * — NOT an explicit `data, replaced_by, replace_at` list — so this
        // read works whether or not the optional grace-period handover columns
        // exist. Naming them throws "unknown column" on any `session` table that
        // predates the handover feature; the catch below would then swallow it and
        // return false for EVERY read, emptying every session and turning every
        // POST (login included) into a 400. read() already guards replaced_by /
        // replace_at with isset(), so their absence just disables handover.
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM `session` WHERE `id` = :id');
            $stmt->execute(['id' => $hashedId]);
        } catch (\PDOException) {
            return false;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { return false; }
        /** @var array<string,mixed> $row */
        return $row;
    }

    /**
     * Mark a session row as replaced by a successor, enabling the grace-period
     * handover window for in-flight requests that still carry the old session ID.
     *
     * Called by ContentManager immediately after session_regenerate_id().
     *
     * @param string $oldHashedId  hashId() of the old session ID.
     * @param string $newHashedId  hashId() of the new session ID.
     */
    public function markReplaced(string $oldHashedId, string $newHashedId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE `session` SET `replaced_by` = :new, `replace_at` = :now
                  WHERE `id` = :old'
            );
            $stmt->execute([
                ':new' => $newHashedId,
                ':now' => time(),
                ':old' => $oldHashedId,
            ]);
        } catch (\PDOException) {
            // Non-fatal — the session can still continue without the handover record.
        }
    }

    /** Expose hashId() publicly so ContentManager can compute the hashed IDs. */
    public function hashIdPublic(string $id): string
    {
        return $this->hashId($id);
    }

    /** Returns the database key for a raw session ID. */
    private function hashId(string $id): string
    {
        return hash('sha512', $id);
    }

    /**
     * Encrypts $data with AES-256-CTR.
     * Keys are derived with HKDF-SHA-256 using distinct info strings
     * so the encryption key and MAC key are domain-separated.
     * Output layout: [32-byte HMAC][16-byte IV][ciphertext]
     */
    private function encrypt(string $id, string $data): string
    {
        $iv         = random_bytes(16);
        // Derive keys by mixing the session ID with the server-side secret.
        // This means a stolen DB row cannot be decrypted without knowing the secret.
        $key        = hash_hkdf('sha256', $this->ikm(), 32, 'astrx-enc', $id);
        $macKey     = hash_hkdf('sha256', $this->ikm(), 32, 'astrx-mac', $id);
        $ciphertext = (string) openssl_encrypt($data, 'AES-256-CTR', $key, OPENSSL_RAW_DATA, $iv);
        $hmac       = hash_hmac('sha256', $iv . $ciphertext, $macKey, true);

        return $hmac . $iv . $ciphertext;
    }

    /**
     * Decrypts and verifies an encrypted session blob.
     * Returns an empty string (treated as an empty session) if the HMAC fails.
     */
    private function decrypt(string $id, string $blob): string
    {
        $hmac       = mb_substr($blob, 0, 32, '8bit');
        $iv         = mb_substr($blob, 32, 16, '8bit');
        $ciphertext = mb_substr($blob, 48, null, '8bit');
        $key        = hash_hkdf('sha256', $this->ikm(), 32, 'astrx-enc', $id);
        $macKey     = hash_hkdf('sha256', $this->ikm(), 32, 'astrx-mac', $id);

        $expectedHmac = hash_hmac('sha256', $iv . $ciphertext, $macKey, true);
        if (!hash_equals($hmac, $expectedHmac)) {
            // Tampered or corrupted: treat as empty session rather than crashing.
            return '';
        }

        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CTR', $key, OPENSSL_RAW_DATA, $iv);
        return $plaintext !== false ? $plaintext : '';
    }
}
