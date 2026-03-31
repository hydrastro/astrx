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

    /** Holds the freshly generated SID so validateId() can confirm it in-process. */
    private ?string $currentSessionId = null;

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

    #[InjectConfig('server_secret')]
    public function setServerSecret(string $secret): void
    {
        $this->serverSecret = $secret;
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
        $graceCutoff = time() - 300; // generous upper bound; ContentManager uses a tighter value
        $stmt2 = $this->pdo->prepare(
            'UPDATE `session` SET `replaced_by` = NULL, `replace_at` = NULL
              WHERE `replace_at` IS NOT NULL AND `replace_at` < :gc'
        );
        $stmt2->execute([':gc' => $graceCutoff]);

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

        if ($replacedBy !== null && $replaceAt !== null) {
            // Row has been marked as replaced — serve the successor instead.
            // The caller (PHP session machinery) will update the cookie on the
            // next response via session_regenerate_id() in ContentManager.
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
        try {
            // Full query including handover columns (requires the updated schema).
            $stmt = $this->pdo->prepare(
                'SELECT `data`, `replaced_by`, `replace_at` FROM `session` WHERE `id` = :id'
            );
            $stmt->execute(['id' => $hashedId]);
        } catch (\PDOException) {
            // Fallback for databases still running the pre-handover schema
            // (missing replaced_by / replace_at columns). Handover is disabled
            // but sessions work normally.  Run the migration in tables.sql to
            // enable session-ID regeneration with grace-period support.
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT `data` FROM `session` WHERE `id` = :id'
                );
                $stmt->execute(['id' => $hashedId]);
            } catch (\PDOException) {
                return false;
            }
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
        $key        = hash_hkdf('sha256', $this->serverSecret, 32, 'astrx-enc', $id);
        $macKey     = hash_hkdf('sha256', $this->serverSecret, 32, 'astrx-mac', $id);
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
        $key        = hash_hkdf('sha256', $this->serverSecret, 32, 'astrx-enc', $id);
        $macKey     = hash_hkdf('sha256', $this->serverSecret, 32, 'astrx-mac', $id);

        $expectedHmac = hash_hmac('sha256', $iv . $ciphertext, $macKey, true);
        if (!hash_equals($hmac, $expectedHmac)) {
            // Tampered or corrupted: treat as empty session rather than crashing.
            return '';
        }

        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CTR', $key, OPENSSL_RAW_DATA, $iv);
        return $plaintext !== false ? $plaintext : '';
    }
}
