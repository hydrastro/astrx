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
 * Data-access for `chat_filters` — the managed word/link enforcement list.
 *
 * `kind`   0 = word (match anywhere in the message), 1 = link (match within a
 *          detected http(s) URL). `action` 0 = block the post, 1 = kick the
 *          poster. `apply_to_mods` 0 = staff exempt, 1 = applies to staff too.
 *
 * Patterns are stored and matched as LITERAL fragments (never as regex); the
 * match itself lives in ChatFilterService.
 */
final class ChatFilterRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return Result<list<array<string,mixed>>> */
    public function all(): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, pattern, kind, action, apply_to_mods,
                        UNIX_TIMESTAMP(created_at) AS created_ts
                   FROM chat_filters
                  ORDER BY kind ASC, pattern ASC'
            );
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<int> new filter id */
    public function add(string $pattern, int $kind, int $action, bool $applyToMods): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO chat_filters (pattern, kind, action, apply_to_mods)
                 VALUES (:p, :k, :a, :m)'
            );
            $stmt->execute([
                ':p' => $pattern,
                ':k' => $kind,
                ':a' => $action,
                ':m' => $applyToMods ? 1 : 0,
            ]);
            return Result::ok((int) $this->pdo->lastInsertId());
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function delete(int $id): Result
    {
        try {
            $this->pdo->prepare('DELETE FROM chat_filters WHERE id = :id')->execute([':id' => $id]);
            return Result::ok(true);
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
