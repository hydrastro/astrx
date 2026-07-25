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
 * Data-access for `board_thread`: create, list (index/catalog ordering), bump,
 * and per-thread counters. OP posts and images are fetched separately (and
 * batched) by PostRepository / ImageRepository so listing stays one query.
 */
final class ThreadRepository
{
    public function __construct(private readonly PDO $pdo) {}

    private const COLS =
        'id, board_id, subject, sticky, locked, cycle, autosage, archived,
         reply_count, image_count, UNIX_TIMESTAMP(bump_time) AS bump_ts,
         UNIX_TIMESTAMP(created_at) AS created_ts';

    /** @return Result<int> */
    public function create(int $boardId, string $subject): Result
    {
        try {
            $this->pdo->prepare('INSERT INTO board_thread (board_id, subject) VALUES (:b, :s)')
                ->execute([':b' => $boardId, ':s' => mb_substr($subject, 0, 255)]);
            $raw = $this->pdo->lastInsertId();
            return Result::ok(is_numeric($raw) ? (int) $raw : 0);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<array<string,mixed>|null> */
    public function byId(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT ' . self::COLS . ' FROM board_thread WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return Result::ok(null);
            }
            /** @var array<string,mixed> $row */
            return Result::ok($row);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * One page of threads (index), ordered sticky then most-recently-bumped.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function page(int $boardId, int $offset, int $limit): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT ' . self::COLS . ' FROM board_thread
                  WHERE board_id = :b AND archived = 0
                  ORDER BY sticky DESC, bump_time DESC, id DESC
                  LIMIT :lim OFFSET :off'
            );
            $stmt->bindValue(':b', $boardId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', max(0, $limit), PDO::PARAM_INT);
            $stmt->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Active threads for the catalog grid (capped), same ordering as the index.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function catalog(int $boardId, int $limit = 200): Result
    {
        return $this->page($boardId, 0, $limit);
    }

    /** @return Result<int> */
    public function countActive(int $boardId): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM board_thread WHERE board_id = :b AND archived = 0');
            $stmt->execute([':b' => $boardId]);
            $v = $stmt->fetchColumn();
            return Result::ok(is_numeric($v) ? (int) $v : 0);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Bump a thread to the top. The caller decides whether a bump is due
     * (non-sage reply, under bump_limit, not autosage) before calling this.
     *
     * @return Result<bool>
     */
    public function touchBump(int $threadId): Result
    {
        try {
            $this->pdo->prepare('UPDATE board_thread SET bump_time = NOW() WHERE id = :id')
                ->execute([':id' => $threadId]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Lock a thread so it accepts no further replies.
     *
     * @return Result<bool>
     */
    public function lock(int $threadId): Result
    {
        try {
            $this->pdo->prepare('UPDATE board_thread SET locked = 1 WHERE id = :id')
                ->execute([':id' => $threadId]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Adjust the reply/image counters (a reply is +1 reply; each image is +1
     * image; an OP with an image is +0 reply / +1 image). Clamped at zero.
     *
     * @return Result<bool>
     */
    public function adjustCounts(int $threadId, int $replyDelta, int $imageDelta): Result
    {
        try {
            $this->pdo->prepare(
                'UPDATE board_thread
                    SET reply_count = GREATEST(0, CAST(reply_count AS SIGNED) + :r),
                        image_count = GREATEST(0, CAST(image_count AS SIGNED) + :i)
                  WHERE id = :id'
            )->execute([':r' => $replyDelta, ':i' => $imageDelta, ':id' => $threadId]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * The thread to drop when a board overflows: the least-recently-bumped
     * non-sticky, non-archived thread. Null if there is nothing prunable.
     *
     * @return Result<int|null>
     */
    public function oldestPrunable(int $boardId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM board_thread
                  WHERE board_id = :b AND archived = 0 AND sticky = 0
                  ORDER BY bump_time ASC, id ASC LIMIT 1'
            );
            $stmt->execute([':b' => $boardId]);
            $v = $stmt->fetchColumn();
            return Result::ok(is_numeric($v) ? (int) $v : null);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
    public function archive(int $threadId): Result
    {
        try {
            $this->pdo->prepare('UPDATE board_thread SET archived = 1 WHERE id = :id')->execute([':id' => $threadId]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
    public function delete(int $threadId): Result
    {
        try {
            $this->pdo->prepare('DELETE FROM board_thread WHERE id = :id')->execute([':id' => $threadId]);
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
