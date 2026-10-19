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
 * Data-access for `board_report`: user-filed reports against a post and the
 * moderator queue that resolves them. A report is keyed UNIQUE on
 * (post_id, reporter_ident) so a poster reporting the same post twice is a
 * silent no-op (INSERT IGNORE) rather than an error.
 */
final class ReportRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Unresolved reports for a board, joined to their post for the post number
     * and body (excerpt). Oldest first — the moderator works the queue in order.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function open(int $boardId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT r.id AS report_id, r.category, r.reason,
                        p.`no` AS post_no, p.thread_id, p.body_html
                   FROM board_report r
                   JOIN board_post p ON p.id = r.post_id
                  WHERE r.board_id = :b AND r.resolved = 0
                  ORDER BY r.created_at ASC, r.id ASC'
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
     * Resolve (dismiss) a report. Scoped to the board so a crafted id from one
     * board cannot resolve another board's report.
     *
     * @return Result<bool>
     */
    public function resolve(int $reportId, int $boardId): Result
    {
        try {
            $this->pdo->prepare(
                'UPDATE board_report SET resolved = 1 WHERE id = :r AND board_id = :b'
            )->execute([':r' => $reportId, ':b' => $boardId]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * File a report. INSERT IGNORE on the (post_id, reporter_ident) unique key
     * makes a duplicate report by the same identity a silent no-op — no leak of
     * whether a previous report exists.
     *
     * @return Result<bool>
     */
    public function create(int $postId, int $boardId, string $reporterIdent, string $category, string $reason): Result
    {
        try {
            $this->pdo->prepare(
                'INSERT IGNORE INTO board_report
                    (post_id, board_id, reporter_ident, reason, category)
                 VALUES (:p, :b, :ident, :reason, :cat)'
            )->execute([
                ':p'     => $postId,
                ':b'     => $boardId,
                ':ident' => mb_substr($reporterIdent, 0, 128),
                ':reason' => mb_substr($reason, 0, 255),
                ':cat'   => $category,
            ]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> */
    public function countOpen(int $boardId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM board_report WHERE board_id = :b AND resolved = 0'
            );
            $stmt->execute([':b' => $boardId]);
            $v = $stmt->fetchColumn();
            return Result::ok(is_numeric($v) ? (int) $v : 0);
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
