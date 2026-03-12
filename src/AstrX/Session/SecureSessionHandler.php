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
 *       `id`        VARCHAR(128) PRIMARY KEY,
 *       `timestamp` INT UNSIGNED NOT NULL,
 *       `data`      MEDIUMBLOB   NOT NULL
 *   );
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

    #[InjectConfig('max_retries')]
    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = max(1, $maxRetries);
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

    public function gc(int $maxLifetime): int|false
    {
        $cutoff = time() - $maxLifetime;

        $count = $this->pdo->prepare(
            'SELECT COUNT(`id`) AS `n` FROM `session` WHERE `timestamp` < :cutoff'
        );
        $count->execute(['cutoff' => $cutoff]);
        $row = $count->fetch(PDO::FETCH_ASSOC);

        $this->pdo->prepare('DELETE FROM `session` WHERE `timestamp` < :cutoff')
            ->execute(['cutoff' => $cutoff]);

        return is_array($row) ? (int) $row['n'] : 0;
    }

    public function read(string $id): string|false
    {
        $row = $this->readRow($this->hashId($id));
        if ($row === false) {
            return '';
        }

        if (!$this->encrypt) {
            return (string) $row['data'];
        }

        return $this->decrypt($id, (string) $row['data']);
    }

    public function write(string $id, string $data): bool
    {
        $hashedId = $this->hashId($id);
        $payload  = $this->encrypt ? $this->encrypt($id, $data) : $data;
        $ts       = time();

        if ($this->readRow($hashedId) !== false) {
            $stmt = $this->pdo->prepare(
                'UPDATE `session` SET `data` = :data, `timestamp` = :ts WHERE `id` = :id'
            );
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `session` (`id`, `timestamp`, `data`) VALUES (:id, :ts, :data)'
            );
        }

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
        $stmt = $this->pdo->prepare('SELECT `data` FROM `session` WHERE `id` = :id');
        $stmt->execute(['id' => $hashedId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : false;
    }

    /** Returns the database key for a raw session ID. */
    private function hashId(string $id): string
    {
        return hash('sha512', $id);
    }

    /**
     * Encrypts $data with AES-256-CTR.
     * Output layout: [32-byte HMAC][16-byte IV][ciphertext]
     */
    private function encrypt(string $id, string $data): string
    {
        $iv         = random_bytes(16);
        $key        = mb_substr($id, 0, 32, '8bit');
        $macKey     = mb_substr($id, 32, null, '8bit');
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
        $key        = mb_substr($id, 0, 32, '8bit');
        $macKey     = mb_substr($id, 32, null, '8bit');

        $expectedHmac = hash_hmac('sha256', $iv . $ciphertext, $macKey, true);
        if (!hash_equals($hmac, $expectedHmac)) {
            // Tampered or corrupted: treat as empty session rather than crashing.
            return '';
        }

        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CTR', $key, OPENSSL_RAW_DATA, $iv);
        return $plaintext !== false ? $plaintext : '';
    }
}
