<?php
declare(strict_types=1);

namespace AstrX\Api;

use AstrX\Api\Diagnostic\InvalidApiKeyDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Owns the lifecycle of API keys: creation, validation, revocation.
 *
 * Key format: `astrx_` prefix + 48 hex chars (24 random bytes). The prefix
 * is purely cosmetic — it makes leaked keys greppable in logs and lets
 * developers spot them when reviewing config files. The prefix is NOT a
 * security signal; the random portion is.
 *
 * Storage: SHA-256 of the raw key in `api_key.key_hash`. Raw keys are NEVER
 * stored. When the user creates a key, the raw value is returned exactly
 * once — they must save it themselves. The framework cannot recover it later.
 *
 * Lookup is constant-time-ish: we SELECT the row by exact hash. SHA-256
 * collisions are not a practical risk given the 192-bit input entropy.
 *
 * Bearer auth: clients send `Authorization: Bearer astrx_<48hex>`. We
 * compute the hash of what was sent, look up the row, and if it matches
 * (and the key is not revoked/expired), the request acts as the owning user.
 */
final class ApiKeyService
{
    /** astrx_ + 48 hex chars = 54 total chars */
    public const string KEY_PREFIX     = 'astrx_';
    public const int    KEY_RANDOM_LEN = 48;          // hex chars, = 24 random bytes
    public const string KEY_REGEX      = '/\Aastrx_[0-9a-f]{48}\z/';

    public function __construct(private readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // Creation
    // -------------------------------------------------------------------------

    /**
     * Create a new API key for the given user. Returns the raw key string —
     * the caller MUST display this to the user immediately because it cannot
     * be recovered.
     *
     * @return Result<string>  The raw key (e.g. "astrx_a3f...")
     */
    public function create(
        string  $hexUserId,
        string  $label,
        ?int    $expiresAtTs = null,
    ): Result {
        $rawKey   = self::KEY_PREFIX . bin2hex(random_bytes(self::KEY_RANDOM_LEN / 2));
        $keyHash  = hash('sha256', $rawKey);
        $idBin    = random_bytes(16);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `api_key`
                    (`id`, `user_id`, `label`, `key_hash`, `created_at`, `expires_at`)
                 VALUES
                    (:id, UNHEX(:uid), :label, :hash, NOW(),
                     CASE WHEN :exp IS NULL THEN NULL ELSE FROM_UNIXTIME(:exp2) END)'
            );
            $stmt->execute([
                ':id'    => $idBin,
                ':uid'   => $hexUserId,
                ':label' => $label,
                ':hash'  => $keyHash,
                ':exp'   => $expiresAtTs,
                ':exp2'  => $expiresAtTs,
            ]);
            return Result::ok($rawKey);
        } catch (PDOException $e) {
            return Result::err(null, Diagnostics::of(
                new InvalidApiKeyDiagnostic(
                    'astrx.api/key_create_failed',
                    DiagnosticLevel::ERROR,
                ),
            ));
        }
    }

    // -------------------------------------------------------------------------
    // Validation (bearer auth)
    // -------------------------------------------------------------------------

    /**
     * Validate a raw bearer key. On success returns the owning user\'s hex ID.
     * On failure returns null. Updates last_used_at on successful lookup.
     *
     * Failure modes (all return null, no diagnostic — leaking why-auth-failed
     * to the network is a side-channel):
     *   - malformed key format
     *   - no matching hash
     *   - revoked = 1
     *   - expires_at in the past
     *
     * Returns null silently. The caller (ContentManager api dispatch) emits
     * a generic UnauthorizedDiagnostic — uniform for any reason.
     */
    public function validate(string $rawKey): ?string
    {
        if (!preg_match(self::KEY_REGEX, $rawKey)) {
            return null;
        }
        $keyHash = hash('sha256', $rawKey);

        try {
            $stmt = $this->pdo->prepare(
                'SELECT LOWER(HEX(`user_id`)) AS user_id, `revoked`,
                        UNIX_TIMESTAMP(`expires_at`) AS expires_ts
                   FROM `api_key`
                  WHERE `key_hash` = :hash
                  LIMIT 1'
            );
            $stmt->execute([':hash' => $keyHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) { return null; }

            if (!empty($row['revoked'])) { return null; }
            $exp = $row['expires_ts'];
            if ($exp !== null && is_numeric($exp) && (int)$exp < time()) {
                return null;
            }

            $userId = is_string($row['user_id'] ?? null) ? (string)$row['user_id'] : '';
            if ($userId === '') { return null; }

            // Best-effort last_used_at update — don't fail the auth if this
            // fails (e.g. read-only replica).
            try {
                $upd = $this->pdo->prepare(
                    'UPDATE `api_key` SET `last_used_at` = NOW() WHERE `key_hash` = :hash'
                );
                $upd->execute([':hash' => $keyHash]);
            } catch (PDOException) { /* no-op */ }

            return $userId;
        } catch (PDOException) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Management
    // -------------------------------------------------------------------------

    /**
     * List ALL keys for the given user — including revoked ones — for the
     * settings-page UI. The query intentionally does not filter on `revoked`;
     * each row carries the `revoked` flag so the UI can show or grey out revoked
     * keys. Returns rows with id, label, created_at, last_used_at, expires_at
     * and revoked — never the hash or raw key.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function listForUser(string $hexUserId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT LOWER(HEX(`id`)) AS id, `label`,
                        UNIX_TIMESTAMP(`created_at`)    AS created_ts,
                        UNIX_TIMESTAMP(`last_used_at`)  AS last_used_ts,
                        UNIX_TIMESTAMP(`expires_at`)    AS expires_ts,
                        `revoked`
                   FROM `api_key`
                  WHERE `user_id` = UNHEX(:uid)
                  ORDER BY `created_at` DESC'
            );
            $stmt->execute([':uid' => $hexUserId]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException) {
            return Result::ok([]);
        }
    }

    /** Revoke a single key by its hex id. Returns true on success. */
    public function revoke(string $hexKeyId, string $hexUserId): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE `api_key`
                    SET `revoked` = 1
                  WHERE `id` = UNHEX(:id) AND `user_id` = UNHEX(:uid)'
            );
            $stmt->execute([':id' => $hexKeyId, ':uid' => $hexUserId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }
}
