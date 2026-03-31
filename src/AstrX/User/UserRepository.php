<?php
declare(strict_types=1);

namespace AstrX\User;

use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\User\Diagnostic\UserDbDiagnostic;
use AstrX\User\DeletionMode;
use PDO;
use PDOException;
use AstrX\Result\DiagnosticLevel;

/**
 * Pure data-access layer for the `user` table.
 *
 * All IDs are 32-char lowercase hex strings. SQL uses UNHEX(:id) for writes
 * and LOWER(HEX(id)) AS id for reads.
 *
 * Passwords are stored as password_hash(PASSWORD_ARGON2ID) strings.
 * Tokens are stored as password_hash() of the raw token.
 */
final class UserRepository
{
    /**
     * Fixed hex ID of the ghost account.
     * All-zeros binary(16), seeded in tables.sql.
     */
    public const GHOST_HEX_ID = '00000000000000000000000000000000';

    public function __construct(private readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * Find an active (not deleted) user by username (case-insensitive).
     *
     * @return Result<array<string,mixed>|null>
     */
    public function findByUsername(string $username): Result
    {
        return $this->fetchOne(
            'SELECT LOWER(HEX(`id`)) AS id, `username`, `password`, `mailbox`,
                    `email`, `display_name`, `type`, `verified`, `avatar`,
                    `login_attempts`, `deleted`
               FROM `user`
              WHERE LOWER(`username`) = LOWER(:u) AND `deleted` = 0',
            [':u' => $username],
        );
    }

    /**
     * Find an active user by username OR recovery email (for password recovery).
     *
     * @return Result<array<string,mixed>|null>
     */
    public function findByUsernameOrEmail(string $usernameOrEmail): Result
    {
        return $this->fetchOne(
            'SELECT LOWER(HEX(`id`)) AS id, `username`, `email`, `display_name`,
                    `type`, `verified`, `avatar`
               FROM `user`
              WHERE (`deleted` = 0)
                AND (LOWER(`username`) = LOWER(:a) OR LOWER(`email`) = LOWER(:b))',
            [':a' => $usernameOrEmail, ':b' => $usernameOrEmail],
        );
    }

    /**
     * List all users for admin panel (excludes password hash for safety).
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function listAll(): Result
    {
        return $this->fetchAll(
            "SELECT LOWER(HEX(`id`)) AS id, `username`, `mailbox`, `email`,
                    `display_name`, `type`, `verified`, `avatar`, `deleted`,
                    `login_attempts`,
                    DATE_FORMAT(`created_at`, '%Y-%m-%d %H:%i:%s') AS created_at,
                    DATE_FORMAT(`last_access`, '%Y-%m-%d %H:%i:%s') AS last_access
               FROM `user`
              ORDER BY `created_at` DESC"
        );
    }

    /** @return Result<bool> */
    public function updateType(string $hexId, int $type): Result
    {
        return $this->exec(
            'UPDATE `user` SET `type` = :t WHERE `id` = UNHEX(:id)',
            [':t' => $type, ':id' => $hexId],
        );
    }

    /**
     * Fetch public profile data for a given hex user ID.
     * Returns only columns safe to display publicly.
     * Returns null if user does not exist or is deleted.
     *
     * @return Result<array<string,mixed>|null>
     */
    public function findPublicById(string $hexId): Result
    {
        return $this->fetchOne(
            "SELECT LOWER(HEX(`id`)) AS id, `username`, `display_name`,
                    `type`, `verified`, `avatar`,
                    DATE_FORMAT(`created_at`, '%Y-%m-%d') AS created_at
               FROM `user`
              WHERE `id` = UNHEX(:id) AND `deleted` = 0",
            [':id' => $hexId],
        );
    }

    /**
     * Fetch public profile data by username (case-insensitive).
     *
     * @return Result<array<string,mixed>|null>
     */
    public function findPublicByUsername(string $username): Result
    {
        return $this->fetchOne(
            "SELECT LOWER(HEX(`id`)) AS id, `username`, `display_name`,
                    `type`, `verified`, `avatar`,
                    DATE_FORMAT(`created_at`, '%Y-%m-%d') AS created_at
               FROM `user`
              WHERE LOWER(`username`) = LOWER(:u) AND `deleted` = 0",
            [':u' => $username],
        );
    }

    /**
     * Find any user (including deleted) by ID.
     *
     * @return Result<array<string,mixed>|null>
     */
    public function findById(string $hexId): Result
    {
        return $this->fetchOne(
            'SELECT LOWER(HEX(`id`)) AS id, `username`, `display_name`, `type`,
                    `verified`, `avatar`, `deleted`,
                    `token_hash`, `token_type`, `token_used`,
                    UNIX_TIMESTAMP(`token_expires_at`) AS token_expires_at
               FROM `user`
              WHERE `id` = UNHEX(:id)',
            [':id' => $hexId],
        );
    }

    /**
     * @return Result<array<string,mixed>|null>
     */
    public function findTokenData(string $hexId): Result
    {
        return $this->fetchOne(
            'SELECT `token_hash`, `token_type`, `token_used`,
                    UNIX_TIMESTAMP(`token_expires_at`) AS token_expires_at
               FROM `user` WHERE `id` = UNHEX(:id)',
            [':id' => $hexId],
        );
    }

    // -------------------------------------------------------------------------
    // Availability checks
    // -------------------------------------------------------------------------

    /** @return Result<bool> true = username available */
    public function isUsernameAvailable(string $username): Result
    {
        return $this->checkAvailability(
            'SELECT 1 FROM `user` WHERE LOWER(`username`) = LOWER(:v)',
            [':v' => $username],
        );
    }

    /** @return Result<bool> true = mailbox available */
    public function isMailboxAvailable(string $mailbox): Result
    {
        return $this->checkAvailability(
            'SELECT 1 FROM `user` WHERE LOWER(`mailbox`) = LOWER(:v)',
            [':v' => $mailbox],
        );
    }

    /** @return Result<bool> true = email available */
    public function isEmailAvailable(string $email): Result
    {
        return $this->checkAvailability(
            'SELECT 1 FROM `user` WHERE LOWER(`email`) = LOWER(:v)',
            [':v' => $email],
        );
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /** @return Result<bool> */
    public function create(
        string  $hexId,
        string  $username,
        string  $passwordHash,
        ?string $mailbox,
        ?string $email,
        ?string $displayName,
        ?string $birth,
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `user`
                    (`id`, `username`, `password`, `mailbox`, `email`,
                     `display_name`, `type`, `birth`, `verified`, `deleted`)
                 VALUES
                    (UNHEX(:id), :username, :password, :mailbox, :email,
                     :display_name, 0, :birth, 0, 0)',
            );
            $stmt->execute([
                               ':id'           => $hexId,
                               ':username'     => $username,
                               ':password'     => $passwordHash,
                               ':mailbox'      => $mailbox,
                               ':email'        => $email,
                               ':display_name' => $displayName,
                               ':birth'        => $birth,
                           ]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->dbErr($e);
        }
    }

    /** @return Result<bool> */
    public function updatePassword(string $hexId, string $hash): Result
    {
        return $this->exec(
            'UPDATE `user` SET `password` = :h WHERE `id` = UNHEX(:id)',
            [':h' => $hash, ':id' => $hexId],
        );
    }

    /** @return Result<bool> */
    public function updateUsername(string $hexId, string $username): Result
    {
        return $this->exec(
            'UPDATE `user` SET `username` = :u WHERE `id` = UNHEX(:id)',
            [':u' => $username, ':id' => $hexId],
        );
    }

    /** @return Result<bool> */
    public function updateDisplayName(string $hexId, string $name): Result
    {
        return $this->exec(
            'UPDATE `user` SET `display_name` = :n WHERE `id` = UNHEX(:id)',
            [':n' => $name, ':id' => $hexId],
        );
    }

    /** @return Result<bool> */
    public function updateRecoveryEmail(string $hexId, string $email): Result
    {
        // Changing recovery email resets verification status
        return $this->exec(
            'UPDATE `user` SET `email` = :e, `verified` = 0 WHERE `id` = UNHEX(:id)',
            [':e' => $email, ':id' => $hexId],
        );
    }

    /** @return Result<bool> */
    public function updateLoginAttempts(string $hexId, int $delta): Result
    {
        $sql = $delta >= 0
            ? 'UPDATE `user` SET `login_attempts` = `login_attempts` + :d WHERE `id` = UNHEX(:id)'
            : 'UPDATE `user` SET `login_attempts` = 0 WHERE `id` = UNHEX(:id)';

        return $this->exec($sql, [':d' => abs($delta), ':id' => $hexId]);
    }

    /** @return Result<bool> */
    public function updateLastAccess(string $hexId): Result
    {
        return $this->exec(
            'UPDATE `user` SET `last_access` = NOW() WHERE `id` = UNHEX(:id)',
            [':id' => $hexId],
        );
    }

    /** @return Result<bool> */
    public function setVerified(string $hexId): Result
    {
        return $this->exec(
            'UPDATE `user` SET `verified` = 1 WHERE `id` = UNHEX(:id)',
            [':id' => $hexId],
        );
    }

    /** @return Result<bool> */
    public function setAvatar(string $hexId, bool $has): Result
    {
        return $this->exec(
            'UPDATE `user` SET `avatar` = :a WHERE `id` = UNHEX(:id)',
            [':a' => (int) $has, ':id' => $hexId],
        );
    }

    /**
     * Store a token hash for an email action.
     *
     * @return Result<bool>
     */
    public function setToken(
        string $hexId,
        string $tokenHash,
        int    $tokenType,
        int    $expiresAt,
    ): Result {
        return $this->exec(
            'UPDATE `user`
                SET `token_hash` = :h, `token_type` = :t,
                    `token_used` = 0,
                    `token_expires_at` = FROM_UNIXTIME(:e)
              WHERE `id` = UNHEX(:id)',
            [':h' => $tokenHash, ':t' => $tokenType, ':e' => $expiresAt, ':id' => $hexId],
        );
    }

    /** @return Result<bool> */
    public function markTokenUsed(string $hexId): Result
    {
        return $this->exec(
            'UPDATE `user` SET `token_used` = 1 WHERE `id` = UNHEX(:id)',
            [':id' => $hexId],
        );
    }

    /** @return Result<bool> */
    /**
     * Full admin view — every column including sensitive fields.
     * @return Result<array<string,mixed>|null>
     */
    public function adminFindById(string $hexId): Result
    {
        return $this->fetchOne(
            'SELECT LOWER(HEX(`id`)) AS id,
                    `username`, `password`, `mailbox`, `email`,
                    `display_name`, `type`, `birth`,
                    DATE_FORMAT(`created_at`, \'%Y-%m-%d %H:%i:%s\') AS created_at,
                    DATE_FORMAT(`last_access`, \'%Y-%m-%d %H:%i:%s\') AS last_access,
                    `login_attempts`, `verified`, `avatar`, `deleted`,
                    `token_hash`, `token_type`, `token_used`,
                    DATE_FORMAT(`token_expires_at`, \'%Y-%m-%d %H:%i:%s\') AS token_expires_at
               FROM `user` WHERE `id` = UNHEX(:id)',
            [':id' => $hexId]
        );
    }

    /**
     * Admin full update — every editable column in one shot.
     * Password hash is stored as-is (admin responsibility to supply valid argon2id).
     * Pass null to leave email/birth/password unchanged.
     * @return Result<bool>
     */
    public function adminUpdate(
        string  $hexId,
        string  $username,
        ?string $password,
        ?string $mailbox,
        ?string $email,
        ?string $displayName,
        int     $type,
        ?string $birth,
        int     $loginAttempts,
        bool    $verified,
        bool    $deleted,
        ?string $createdAt  = null,
        ?string $lastAccess = null,
    ): Result {
        try {
            $sets  = [];
            $params = [':id' => $hexId];

            $fields = [
                'username'       => $username,
                'mailbox'        => $mailbox,
                'email'          => $email,
                'display_name'   => $displayName,
                'type'           => $type,
                'birth'          => $birth,
                'login_attempts' => $loginAttempts,
                'verified'       => (int) $verified,
                'deleted'        => (int) $deleted,
            ];
            if ($password !== null && $password !== '') {
                $fields['password'] = $password;
            }
            if ($createdAt !== null)  { $fields['created_at']  = $createdAt; }
            if ($lastAccess !== null) { $fields['last_access'] = $lastAccess; }
            foreach ($fields as $col => $val) {
                $sets[]           = "`{$col}` = :{$col}";
                $params[":{$col}"] = $val;
            }

            $this->pdo->prepare(
                'UPDATE `user` SET ' . implode(', ', $sets) . ' WHERE `id` = UNHEX(:id)'
            )->execute($params);
            return Result::ok(true);
        } catch (\PDOException $e) {
            return $this->dbErr($e);
        }
    }

    /** @return Result<mixed> */
        public function softDelete(string $hexId): Result
    {
        return $this->exec(
            'UPDATE `user`
                SET `deleted` = 1,
                    `username` = NULL, `password` = NULL, `mailbox` = NULL,
                    `email` = NULL, `display_name` = NULL, `birth` = NULL,
                    `token_hash` = NULL, `token_type` = NULL,
                    `token_used` = 0, `token_expires_at` = NULL,
                    `last_access` = NULL, `login_attempts` = 0,
                    `verified` = 0, `avatar` = 0
              WHERE `id` = UNHEX(:id)',
            [':id' => $hexId],
        );
    }

    /**
     * Fetch just the password hash for auth purposes.
     * Kept separate so we never accidentally expose the hash in broader queries.
     *
     * @return Result<string|null>
     */
    public function findPasswordHash(string $hexId): Result
    {
        $result = $this->fetchOne(
            'SELECT `password` FROM `user` WHERE `id` = UNHEX(:id) AND `deleted` = 0',
            [':id' => $hexId],
        );
        if (!$result->isOk()) {
            return Result::err(null, $result->diagnostics());
        }
        $row = $result->unwrap();
        return Result::ok($row !== null ? (is_scalar($row['password']) ? (string)$row['password'] : '') : null);
    }


    // -------------------------------------------------------------------------
    // Deletion
    // -------------------------------------------------------------------------

    /**
     * Full delete — physically remove the user row.
     * The `comment.user_id` FK is ON DELETE SET NULL, so all comments from this
     * user will have user_id set to NULL by the database. Thread structure is
     * preserved but authorship is permanently lost.
     *
     * For hard_redact (where comments are reassigned to the ghost account
     * BEFORE this call), call reassignCommentsToGhost() first.
     *
     * @return Result<bool>
     */
    public function fullDelete(string $hexId): Result
    {
        return $this->exec(
            'DELETE FROM `user` WHERE `id` = UNHEX(:id)',
            [':id' => $hexId],
        );
    }

    /**
     * Hard redact — wipe all PII and mark the row as a tombstone.
     * The username is replaced with a collision-safe placeholder that frees
     * the original username for re-registration.
     *
     * Call reassignCommentsToGhost() BEFORE this to preserve thread structure.
     *
     * @return Result<bool>
     */
    public function hardRedact(string $hexId): Result
    {
        // The tombstone username must be unique but not re-registerable.
        // Prefixed with \x00 (null byte) which is illegal in normal usernames.
        $tombstone = 'deleted_' . $hexId;
        return $this->exec(
            'UPDATE `user`
                SET `deletion_mode` = :mode,
                    `deleted`        = 1,
                    `username`       = :tomb,
                    `password`       = NULL,
                    `mailbox`        = NULL,
                    `email`          = NULL,
                    `display_name`   = NULL,
                    `birth`          = NULL,
                    `avatar`         = 0,
                    `verified`       = 0,
                    `login_attempts` = 0,
                    `token_hash`     = NULL,
                    `token_type`     = NULL,
                    `token_used`     = 0,
                    `token_expires_at` = NULL,
                    `last_access`    = NULL
              WHERE `id` = UNHEX(:id)',
            [':mode' => DeletionMode::HARD_REDACT->value, ':tomb' => $tombstone, ':id' => $hexId],
        );
    }

    /**
     * Soft redact — set deletion_mode + deleted flag only.
     * All data stays intact; the rendering layer shows [deleted].
     * Reversible by calling setDeletionMode(NONE).
     *
     * @return Result<bool>
     */
    public function softRedact(string $hexId): Result
    {
        return $this->exec(
            'UPDATE `user` SET `deletion_mode` = :mode, `deleted` = 1
              WHERE `id` = UNHEX(:id)',
            [':mode' => DeletionMode::SOFT_REDACT->value, ':id' => $hexId],
        );
    }

    /**
     * Keep visible — mark account as voluntarily closed.
     * Content and profile remain visible; login is blocked by deleted=1.
     *
     * @return Result<bool>
     */
    public function keepVisible(string $hexId): Result
    {
        return $this->exec(
            'UPDATE `user` SET `deletion_mode` = :mode, `deleted` = 0
              WHERE `id` = UNHEX(:id)',
            [':mode' => DeletionMode::KEEP_VISIBLE->value, ':id' => $hexId],
        );
    }

    /**
     * Keep suspended — admin-disabled; content hidden from public.
     *
     * @return Result<bool>
     */
    public function keepSuspended(string $hexId): Result
    {
        return $this->exec(
            'UPDATE `user` SET `deletion_mode` = :mode, `deleted` = 1
              WHERE `id` = UNHEX(:id)',
            [':mode' => DeletionMode::KEEP_SUSPENDED->value, ':id' => $hexId],
        );
    }

    /**
     * Change deletion_mode directly (e.g. to restore a soft_redact).
     *
     * @return Result<bool>
     */
    public function setDeletionMode(string $hexId, DeletionMode $mode): Result
    {
        $deleted = match ($mode) {
            DeletionMode::NONE, DeletionMode::KEEP_VISIBLE => 0,
            default => 1,
        };
        return $this->exec(
            'UPDATE `user` SET `deletion_mode` = :mode, `deleted` = :d
              WHERE `id` = UNHEX(:id)',
            [':mode' => $mode->value, ':d' => $deleted, ':id' => $hexId],
        );
    }

    /**
     * Reassign all comments authored by $hexId to the ghost account.
     * Called before hard_redact to preserve thread structure.
     *
     * @return Result<bool>
     */
    public function reassignCommentsToGhost(string $hexId): Result
    {
        return $this->exec(
            'UPDATE `comment` SET `user_id` = UNHEX(:ghost)
              WHERE `user_id` = UNHEX(:id)',
            [':ghost' => self::GHOST_HEX_ID, ':id' => $hexId],
        );
    }

    /**
     * Fetch only the email for a given user ID (used for avatar seed).
     * Returns null if user not found or deleted.
     *
     * @return Result<string|null>
     */
    public function findEmailById(string $hexId): Result
    {
        $result = $this->fetchOne(
            'SELECT `email` FROM `user` WHERE `id` = UNHEX(:id) AND `deleted` = 0',
            [':id' => $hexId],
        );
        if (!$result->isOk()) {
            return Result::err(null, $result->diagnostics());
        }
        $row = $result->unwrap();
        if (!is_array($row)) {
            return Result::ok(null);
        }
        /** @var array<string,mixed> $row */
        $email = $row['email'] ?? null;
        return Result::ok(is_string($email) ? $email : null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $params
     * @return Result<list<array<string,mixed>>>
     */
    private function fetchAll(string $sql, array $params = []): Result
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->dbErr($e);
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return Result<array<string,mixed>|null>
     */
    private function fetchOne(string $sql, array $params): Result
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) { return Result::ok(null); }
            /** @var array<string,mixed> $fetched */
            return Result::ok($fetched);
        } catch (PDOException $e) {
            return $this->dbErr($e);
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return Result<bool>
     */
    private function checkAvailability(string $sql, array $params): Result
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return Result::ok($stmt->fetch() === false);
        } catch (PDOException $e) {
            return $this->dbErr($e);
        }
    }

    /**
     * @param array<string,mixed> $params
     * @return Result<bool>
     */
    private function exec(string $sql, array $params): Result
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->dbErr($e);
        }
    }

    /** @return Result<never> */
    private function dbErr(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new UserDbDiagnostic(
                                                      'astrx.user/db_error', DiagnosticLevel::ERROR,
                                                      $e->getMessage(),
                                                  )));
    }
}
