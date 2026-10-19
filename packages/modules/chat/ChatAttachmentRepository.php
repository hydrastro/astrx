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
 * Data-access for `chat_attachment` — one image per attached message.
 *
 * `token` (random, 32 hex chars) is the only public handle: the serve route
 * looks up by token, so the on-disk `stored_name` is never exposed and files
 * can't be enumerated.
 */
final class ChatAttachmentRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return Result<int> new attachment id */
    public function create(
        int    $messageId,
        string $token,
        string $storedName,
        string $mime,
        int    $size,
        int    $width,
        int    $height,
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO chat_attachment (message_id, token, stored_name, mime, byte_size, width, height)
                 VALUES (:m, :t, :n, :mime, :sz, :w, :h)'
            );
            $stmt->execute([
                ':m' => $messageId, ':t' => $token, ':n' => $storedName,
                ':mime' => $mime, ':sz' => $size, ':w' => $width, ':h' => $height,
            ]);
            return Result::ok((int) $this->pdo->lastInsertId());
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<array<string,mixed>|null> */
    public function findByToken(string $token): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, message_id, token, stored_name, mime, byte_size, width, height
                   FROM chat_attachment WHERE token = :t'
            );
            $stmt->execute([':t' => $token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) { return Result::ok(null); }
            /** @var array<string,mixed> $row */
            return Result::ok($row);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Attachments for a set of message ids, keyed by message_id (for the stream).
     *
     * @param list<int> $messageIds
     * @return Result<array<int,array<string,mixed>>>
     */
    public function forMessages(array $messageIds): Result
    {
        $ids = array_values(array_filter($messageIds, static fn(int $i): bool => $i > 0));
        if ($ids === []) {
            return Result::ok([]);
        }
        $place = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $this->pdo->prepare(
                "SELECT message_id, token, mime, width, height
                   FROM chat_attachment WHERE message_id IN ($place)"
            );
            $stmt->execute($ids);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $mid = is_scalar($r['message_id'] ?? null) ? (int) $r['message_id'] : 0;
                if ($mid > 0) {
                    $out[$mid] = $r;
                }
            }
            return Result::ok($out);
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
