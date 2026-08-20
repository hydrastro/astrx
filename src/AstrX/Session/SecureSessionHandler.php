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
 *
 * Liveness: rotation keeps the OLD row alive on purpose (in-flight Tor requests
 * may still carry the previous id), so "does the row exist" is NOT the same
 * question as "is this session still valid". rowIsLive() is the single answer;
 * validateId() admits with it, read() enforces with it, gc() deletes by its SQL
 * mirror. Any new code path that decides a session is dead belongs there too,
 * not in a fourth private rule.
 */
final class SecureSessionHandler implements
    SessionHandlerInterface,
    SessionIdInterface,
    SessionUpdateTimestampHandlerInterface
{
    private int $sidBytes = 128;
    private bool $encrypt = true;
    private int $maxRetries = 10;

    /** Holds the freshly generated SID so validateId() can confirm it in-process. */
    private ?string $currentSessionId = null;

    /** Seconds an old (rotated-away) session row stays valid after regeneration.
     *  Configurable via Session.regenerate_grace_period; enforced by rowIsLive(). */
    private int $graceSeconds = 30;

    /**
     * Hashed ids this request has destroyed.
     *
     * PHP calls write()/updateTimestamp() at the end of EVERY request, including
     * one whose read() just decided the session was dead and deleted it — the
     * UPSERT then re-INSERTs the very row read() removed, with a fresh
     * `timestamp` and no `replace_at`. The row comes back cleaner than it left:
     * validateId() sees it again, inactivity GC restarts its clock, and the
     * delete never sticks. Refusing to (re)write an id destroyed in this request
     * is what makes destroy() final.
     *
     * @var array<string,true>
     */
    private array $destroyedThisRequest = [];

    public function __construct(
        private readonly PDO $pdo,
        /**
         * The install's secret.
         *
         * Defaulted — unlike UserService's and AvatarService's, which are
         * required — because public/info.php wires this handler by hand
         * (`new SecureSessionHandler($pdo)`) and applies Session.config.php
         * itself. Injector::createClass() skips optional parameters, so under
         * the framework this is a private instance rather than the shared one;
         * that is safe here and only here, because setServerSecret() (an
         * #[InjectConfig] setter the module loader always runs) forwards the
         * configured value into it. With no configured value both instances
         * read or create the same per-install file, so every consumer still
         * agrees on the key.
         */
        private readonly ServerSecret $secret = new ServerSecret(),
    ) {}

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

    /**
     * Kept for the standalone wiring in public/info.php, which applies
     * Session.config.php by hand. Forwards to the ServerSecret value object,
     * which owns resolution (and the decision to FAIL rather than degrade to a
     * per-request random key).
     */
    #[InjectConfig('server_secret')]
    public function setServerSecret(string $secret): void
    {
        $this->secret->setConfigured($secret);
    }

    /**
     * The HKDF input key material: this install's server secret, so a stolen DB
     * row cannot be decrypted without it.
     *
     * @throws \RuntimeException when no stable secret exists — see ServerSecret.
     */
    private function ikm(): string
    {
        return $this->secret->bytes();
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
        $hashedId = $this->hashId($id);
        // Remember BEFORE the DELETE: write()/updateTimestamp() run after this,
        // at the end of the request, and would otherwise UPSERT the row straight
        // back. See $destroyedThisRequest.
        $this->destroyedThisRequest[$hashedId] = true;

        $stmt = $this->pdo->prepare('DELETE FROM `session` WHERE `id` = :id');
        $stmt->execute(['id' => $hashedId]);
        return true;
    }

    public function gc(int $maxLifetime): int
    {
        $now = time();

        $stmt = $this->pdo->prepare(
            'DELETE FROM `session` WHERE `timestamp` < :cutoff'
        );
        $stmt->execute(['cutoff' => $now - $maxLifetime]);
        $deleted = $stmt->rowCount();

        // The SQL mirror of rowIsLive()'s handover rule: DELETE the rotated-away
        // rows whose grace window has closed.
        //
        // This statement used to `SET replaced_by = NULL, replace_at = NULL`
        // instead — and that UN-ROTATED the session. Rotation keeps the old row
        // alive (session_regenerate_id(false) even writes the current,
        // AUTHENTICATED $_SESSION into it), and the ONLY thing that ever killed
        // it was read()'s replace_at check. Clearing replace_at removed the
        // evidence: read() then took the row as an ordinary live session and
        // served the post-login snapshot to whoever still held the old id, while
        // every use refreshed `timestamp` so inactivity GC never reached it
        // either. On the shipped php.ini (gc_probability=1, gc_divisor=1000,
        // gc_maxlifetime=1440) that resurrected a rotated-away id for roughly
        // 1410 s per rotation. Deleting is the only correct action here.
        //
        // Wrapped so a `session` table WITHOUT the optional handover columns
        // (a legacy install that hasn't migrated) doesn't throw an uncaught
        // PDOException when GC fires — the row DELETE above already ran and is
        // what matters.
        try {
            $stmt2 = $this->pdo->prepare(
                'DELETE FROM `session`
                  WHERE `replace_at` IS NOT NULL AND `replace_at` < :gc'
            );
            // configurable via Session.regenerate_grace_period
            $stmt2->execute([':gc' => $now - $this->graceSeconds]);
            $deleted += $stmt2->rowCount();
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

        // ONE liveness rule, shared with validateId() and gc(). Enforcing it here
        // as well as at admission matters because read() is reached even when
        // session.use_strict_mode is off and validateId() is never consulted.
        if (!$this->rowIsLive($row, time())) {
            $this->destroy($id);
            return '';
        }

        // If this row has been replaced and we are within the grace period,
        // transparently redirect to the successor session.
        $replacedBy = isset($row['replaced_by']) && is_string($row['replaced_by'])
            ? $row['replaced_by'] : null;
        $replaceAt  = $this->replaceAtOf($row);

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

        // Never resurrect a row this request destroyed. Concretely: a request
        // arriving on a rotated-away session id gets destroy()'d in read(), then
        // PHP's end-of-request write() ran this UPSERT with the SAME id and
        // INSERTed it again — a fresh `timestamp`, no `replace_at`, and the dead
        // id accepted by validateId() from then on. The DELETE has to be final.
        if (isset($this->destroyedThisRequest[$hashedId])) {
            return true;
        }

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

        $hashedId = $this->hashId($id);
        if (isset($this->destroyedThisRequest[$hashedId])) {
            return false;
        }

        // Row EXISTENCE is not liveness. The old `SELECT 1` accepted any row,
        // including a rotated-away one whose grace window had long closed —
        // admission said yes while read() said no, and gc() had a third opinion.
        // rowIsLive() is the single rule all three now consult.
        $row = $this->readRow($hashedId);
        if ($row === false) {
            return false;
        }

        return $this->rowIsLive($row, time());
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * THE liveness rule for a session row, consulted by validateId()
     * (admission), read() (enforcement) and mirrored in SQL by gc() (cleanup).
     *
     * Before this existed the three disagreed, and the disagreement was
     * exploitable: validateId() checked only that the row existed, read()
     * checked the grace window, and gc() erased the very column read() checked.
     *
     * Scope note — INACTIVITY is deliberately NOT part of this predicate. PHP's
     * session.gc_maxlifetime is probabilistic by design (it is only applied when
     * gc_probability/gc_divisor fires), so enforcing it on every read would sign
     * every user out after 1440 idle seconds on the shipped php.ini: a
     * behaviour change, not a fix. gc() remains the sole consumer of the
     * inactivity bound, with $maxLifetime supplied by PHP. The handover bound
     * below is the rule all three share.
     *
     * @param array<string,mixed> $row
     */
    private function rowIsLive(array $row, int $now): bool
    {
        $replaceAt = $this->replaceAtOf($row);

        // A rotated-away row is valid only inside the grace window. Its
        // `timestamp` is refreshed on every use, so inactivity GC can never
        // reap it; without this bound a captured old sid stays valid forever and
        // rotation buys nothing. The legitimate client already moved to the
        // successor id via Set-Cookie, so only stale in-flight — or stolen —
        // requests still arrive on the old id.
        return $replaceAt === null || ($now - $replaceAt) <= $this->graceSeconds;
    }

    /**
     * `replace_at` as an int, or null when the row was never rotated (or the
     * column does not exist on a legacy schema).
     *
     * Coerced via is_numeric()/cast rather than is_int(): under PDO
     * ATTR_EMULATE_PREPARES=true the INT column comes back as a STRING, so an
     * is_int() check would yield null and silently DISABLE the grace-period
     * expiry — letting a rotated-away/stolen old sid stay valid indefinitely.
     * A numeric string is honoured either way.
     *
     * @param array<string,mixed> $row
     */
    private function replaceAtOf(array $row): ?int
    {
        return isset($row['replace_at']) && is_numeric($row['replace_at'])
            ? (int) $row['replace_at']
            : null;
    }

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
