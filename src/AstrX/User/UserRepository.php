<?php

declare(strict_types = 1);

namespace AstrX\User;

use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\User\Diagnostic\UserDbDiagnostic;
use PDO;
use PDOException;

/**
 * Pure data-access layer for the `user` table.
 * All IDs are 32-char lowercase hex strings. SQL uses UNHEX(:id) for writes
 * and LOWER(HEX(id)) AS id for reads.
 * Passwords are stored as password_hash(PASSWORD_ARGON2ID) strings.
 * Tokens are stored as password_hash() of the raw token.
 */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * Find an active (not deleted) user by username (case-insensitive).
     * @return Result<array<string,mixed>|null>
     */
    public function findByUsername(string $username)
    : Result {
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
     * @return Result<array<string,mixed>|null>
     */
    public function findByUsernameOrEmail(string $usernameOrEmail)
    : Result {
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
     * Find any user (including deleted) by ID.
     * @return Result<array<string,mixed>|null>
     */
    public function findById(string $hexId)
    : Result {
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
    public function findTokenData(string $hexId)
    : Result {
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
    public function isUsernameAvailable(string $username)
    : Result {
        return $this->checkAvailability(
            'SELECT 1 FROM `user` WHERE LOWER(`username`) = LOWER(:v)',
            [':v' => $username],
        );
    }

    /** @return Result<bool> true = mailbox available */
    public function isMailboxAvailable(string $mailbox)
    : Result {
        return $this->checkAvailability(
            'SELECT 1 FROM `user` WHERE LOWER(`mailbox`) = LOWER(:v)',
            [':v' => $mailbox],
        );
    }

    /** @return Result<bool> true = email available */
    public function isEmailAvailable(string $email)
    : Result {
        return $this->checkAvailability(
            'SELECT 1 FROM `user` WHERE LOWER(`email`) = LOWER(:v)',
            [':v' => $email],
        );
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /** @return Result<true> */
    public function create(
        string $hexId,
        string $username,
        string $passwordHash,
        ?string $mailbox,
        ?string $email,
        ?string $displayName,
        ?string $birth,
    )
    : Result {
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
                               ':id' => $hexId,
                               ':username' => $username,
                               ':password' => $passwordHash,
                               ':mailbox' => $mailbox,
                               ':email' => $email,
                               ':display_name' => $displayName,
                               ':birth' => $birth,
                           ]);

            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->dbErr($e);
        }
    }

    /** @return Result<true> */
    public function updatePassword(string $hexId, string $hash)
    : Result {
        return $this->exec(
            'UPDATE `user` SET `password` = :h WHERE `id` = UNHEX(:id)',
            [':h' => $hash, ':id' => $hexId],
        );
    }

    /** @return Result<true> */
    public function updateUsername(string $hexId, string $username)
    : Result {
        return $this->exec(
            'UPDATE `user` SET `username` = :u WHERE `id` = UNHEX(:id)',
            [':u' => $username, ':id' => $hexId],
        );
    }

    /** @return Result<true> */
    public function updateDisplayName(string $hexId, string $name)
    : Result {
        return $this->exec(
            'UPDATE `user` SET `display_name` = :n WHERE `id` = UNHEX(:id)',
            [':n' => $name, ':id' => $hexId],
        );
    }

    /** @return Result<true> */
    public function updateRecoveryEmail(string $hexId, string $email)
    : Result {
        // Changing recovery email resets verification status
        return $this->exec(
            'UPDATE `user` SET `email` = :e, `verified` = 0 WHERE `id` = UNHEX(:id)',
            [':e' => $email, ':id' => $hexId],
        );
    }

    /** @return Result<true> */
    public function updateLoginAttempts(string $hexId, int $delta)
    : Result {
        $sql = $delta >= 0 ?
            'UPDATE `user` SET `login_attempts` = `login_attempts` + :d WHERE `id` = UNHEX(:id)' :
            'UPDATE `user` SET `login_attempts` = 0 WHERE `id` = UNHEX(:id)';

        return $this->exec($sql, [':d' => abs($delta), ':id' => $hexId]);
    }

    /** @return Result<true> */
    public function updateLastAccess(string $hexId)
    : Result {
        return $this->exec(
            'UPDATE `user` SET `last_access` = NOW() WHERE `id` = UNHEX(:id)',
            [':id' => $hexId],
        );
    }

    /** @return Result<true> */
    public function setVerified(string $hexId)
    : Result {
        return $this->exec(
            'UPDATE `user` SET `verified` = 1 WHERE `id` = UNHEX(:id)',
            [':id' => $hexId],
        );
    }

    /** @return Result<true> */
    public function setAvatar(string $hexId, bool $has)
    : Result {
        return $this->exec(
            'UPDATE `user` SET `avatar` = :a WHERE `id` = UNHEX(:id)',
            [':a' => (int)$has, ':id' => $hexId],
        );
    }

    /**
     * Store a token hash for an email action.
     * @return Result<true>
     */
    public function setToken(
        string $hexId,
        string $tokenHash,
        int $tokenType,
        int $expiresAt,
    )
    : Result {
        return $this->exec(
            'UPDATE `user`
                SET `token_hash` = :h, `token_type` = :t,
                    `token_used` = 0,
                    `token_expires_at` = FROM_UNIXTIME(:e)
              WHERE `id` = UNHEX(:id)',
            [
                ':h' => $tokenHash,
                ':t' => $tokenType,
                ':e' => $expiresAt,
                ':id' => $hexId
            ],
        );
    }

    /** @return Result<true> */
    public function markTokenUsed(string $hexId)
    : Result {
        return $this->exec(
            'UPDATE `user` SET `token_used` = 1 WHERE `id` = UNHEX(:id)',
            [':id' => $hexId],
        );
    }

    /** @return Result<true> */
    public function softDelete(string $hexId)
    : Result {
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
     * @return Result<string|null>
     */
    public function findPasswordHash(string $hexId)
    : Result {
        $result = $this->fetchOne(
            'SELECT `password` FROM `user` WHERE `id` = UNHEX(:id) AND `deleted` = 0',
            [':id' => $hexId],
        );
        if (!$result->isOk()) {
            return $result;
        }
        $row = $result->unwrap();

        return Result::ok($row !== null ? (string)$row['password'] : null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return Result<array<string,mixed>|null> */
    private function fetchOne(string $sql, array $params)
    : Result {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return Result::ok($row === false ? null : $row);
        } catch (PDOException $e) {
            return $this->dbErr($e);
        }
    }

    /** @return Result<bool> true = available (no row found) */
    private function checkAvailability(string $sql, array $params)
    : Result {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return Result::ok($stmt->fetch() === false);
        } catch (PDOException $e) {
            return $this->dbErr($e);
        }
    }

    /** @return Result<true> */
    private function exec(string $sql, array $params)
    : Result {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->dbErr($e);
        }
    }

    private function dbErr(PDOException $e)
    : Result {
        return Result::err(
            false,
            Diagnostics::of(
                new UserDbDiagnostic(
                    UserDbDiagnostic::ID,
                    UserDbDiagnostic::LEVEL,
                    $e->getMessage(),
                )
            )
        );
    }
}