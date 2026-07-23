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
 * Data-access for `chat_pm` — private messages between two idents.
 * Retention is enforced with expires_at (lazy GC on read, like chat_message).
 */
final class ChatPmRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return Result<int> new id */
    public function create(
        string  $fromIdent,
        string  $fromNick,
        ?string $fromHexUserId,
        string  $toIdent,
        string  $toNick,
        ?string $color,
        string  $content,
        string  $expiresAt,
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO chat_pm (from_ident, from_nick, from_user_id, to_ident, to_nick, color, content, expires_at)
                 VALUES (:fi, :fn, UNHEX(:fu), :ti, :tn, :color, :content, :exp)'
            );
            $stmt->execute([
                ':fi' => $fromIdent, ':fn' => $fromNick, ':fu' => $fromHexUserId,
                ':ti' => $toIdent, ':tn' => $toNick, ':color' => $color,
                ':content' => $content, ':exp' => $expiresAt,
            ]);
            return Result::ok((int) $this->pdo->lastInsertId());
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Resolve a registered, active member by username or display name to their
     * chat ident (lowercase-hex user id) — lets a PM be addressed to a member
     * who is not currently in the roster (offline delivery). :n1/:n2 avoid
     * reusing a placeholder (native prepares reject that).
     *
     * @return Result<array{ident: string, nick: string}|null>
     */
    public function findMemberByNick(string $name): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT LOWER(HEX(id)) AS ident, COALESCE(display_name, username) AS nick
                   FROM `user`
                  WHERE (username = :n1 OR display_name = :n2) AND verified = 1 AND deleted = 0
                  LIMIT 1'
            );
            $stmt->execute([':n1' => $name, ':n2' => $name]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) { return Result::ok(null); }
            /** @var array<string,mixed> $row */
            $ident = is_scalar($row['ident'] ?? null) ? (string) $row['ident'] : '';
            $nick  = is_scalar($row['nick']  ?? null) ? (string) $row['nick']  : $name;
            if ($ident === '') { return Result::ok(null); }
            return Result::ok(['ident' => $ident, 'nick' => $nick]);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * The most recent conversation lines involving $ident (sent or received),
     * newest first.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function conversation(string $ident, int $limit): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, from_ident, from_nick, LOWER(HEX(from_user_id)) AS from_user_id,
                        to_ident, to_nick, color, content,
                        UNIX_TIMESTAMP(created_at) AS created_ts, read_at
                   FROM chat_pm
                  WHERE (to_ident = :id OR from_ident = :id2) AND expires_at > NOW()
                  ORDER BY created_at DESC, id DESC LIMIT :lim'
            );
            $stmt->bindValue(':id', $ident);
            $stmt->bindValue(':id2', $ident);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<int> unread count for $ident */
    public function unreadCount(string $ident): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM chat_pm WHERE to_ident = :id AND read_at IS NULL AND expires_at > NOW()'
            );
            $stmt->execute([':id' => $ident]);
            return Result::ok((int) $stmt->fetchColumn());
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function markReadFor(string $ident): Result
    {
        try {
            $this->pdo->prepare('UPDATE chat_pm SET read_at = NOW() WHERE to_ident = :id AND read_at IS NULL')
                ->execute([':id' => $ident]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<int> rows removed */
    public function gcExpired(): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM chat_pm WHERE expires_at <= NOW()');
            $stmt->execute();
            return Result::ok($stmt->rowCount());
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
