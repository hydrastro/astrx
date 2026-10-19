<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Imageboard\Diagnostic\ImageboardDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Data-access for `board_mod`: the per-board volunteer staff roster (a granular
 * tier below the global MOD/ADMIN groups). Roles are 'janitor' or 'moderator'.
 * user_id is BINARY(16): read as LOWER(HEX(...)), written with UNHEX(?).
 */
final class BoardModRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * The staff roster for a board, joined to `user` for the display username.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function roster(int $boardId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT LOWER(HEX(m.user_id)) AS user_id, u.username, m.role
                   FROM board_mod m
                   JOIN `user` u ON u.id = m.user_id
                  WHERE m.board_id = :b
                  ORDER BY m.role ASC, u.username ASC'
            );
            $stmt->execute([':b' => $boardId]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Grant (or re-grant) a role. ON DUPLICATE KEY UPDATE flips an existing
     * roster entry's role in place. The role is a controller-validated enum
     * value. Native prepares forbid re-using a named placeholder, so the role
     * binds twice under two names.
     *
     * @return Result<bool>
     */
    public function grant(int $boardId, string $hexUserId, string $role): Result
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO board_mod (board_id, user_id, role)
                 VALUES (:b, UNHEX(:u), :r)
                 ON DUPLICATE KEY UPDATE role = :r2'
            )->execute([
                ':b'  => $boardId,
                ':u'  => $hexUserId,
                ':r'  => $role,
                ':r2' => $role,
            ]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
    public function revoke(int $boardId, string $hexUserId): Result
    {
        try {
            $this->pdo->prepare(
                'DELETE FROM board_mod WHERE board_id = :b AND user_id = UNHEX(:u)'
            )->execute([':b' => $boardId, ':u' => $hexUserId]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new ImageboardDbDiagnostic(
            'astrx.imageboard/db_error', DiagnosticLevel::ERROR, $e->getMessage()
        )));
    }
}
