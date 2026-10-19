<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Chat\Diagnostic\ChatDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Data-access for `chat_report` — user reports of messages (#132).
 *
 * One row per (message, reporter) via a UNIQUE key, so a given participant
 * reporting the same message twice is a no-op. ON DELETE CASCADE ties reports
 * to their message.
 */
final class ChatReportRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return Result<bool> */
    public function create(int $messageId, string $reporterIdent): Result
    {
        try {
            $this->pdo->prepare(
                'INSERT IGNORE INTO chat_report (message_id, reporter_ident) VALUES (:m, :r)'
            )->execute([':m' => $messageId, ':r' => $reporterIdent]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Pending reports grouped by message, most-reported first, with the message
     * content + author for review.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function pending(): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT r.message_id,
                        COUNT(*) AS report_count,
                        m.nick        AS nick,
                        m.content     AS content,
                        m.user_id IS NOT NULL AS is_member
                   FROM chat_report r
                   JOIN chat_message m ON m.id = r.message_id
                  WHERE r.resolved = 0
                  GROUP BY r.message_id, m.nick, m.content, m.user_id
                  ORDER BY report_count DESC, MIN(r.created_at) ASC'
            );
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Content of a single message (for link extraction).
     *
     * @return Result<string|null>
     */
    public function messageContent(int $messageId): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT content FROM chat_message WHERE id = :id');
            $stmt->execute([':id' => $messageId]);
            $v = $stmt->fetchColumn();
            return Result::ok(is_string($v) ? $v : null);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Resolve every report on a message.
     *
     * @return Result<bool>
     */
    public function resolveMessage(int $messageId): Result
    {
        try {
            $this->pdo->prepare('UPDATE chat_report SET resolved = 1 WHERE message_id = :id')
                ->execute([':id' => $messageId]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<int> */
    public function countPending(): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(DISTINCT message_id) FROM chat_report WHERE resolved = 0');
            $stmt->execute();
            return Result::ok((int) $stmt->fetchColumn());
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new ChatDbDiagnostic(
            'astrx.chat/db_error', DiagnosticLevel::ERROR, $e->getMessage()
        )));
    }
}
