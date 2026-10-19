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
 * Data-access for `chat_presence` — the live roster.
 *
 * Identity key `ident` is a member's lowercase-hex user id or a guest token.
 * `status`: 0 waiting, 1 active, 2 kicked, 3 pending (awaiting moderator
 * approval). `ip` is stored packed (inet_pton) and read back as a printable
 * string via INET6_NTOA for moderation.
 *
 * Time windows are passed as pre-computed cutoff datetimes (computed in PHP,
 * mirroring ChatRepository) rather than bound INTERVAL expressions.
 */
final class ChatPresenceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return Result<bool> */
    public function upsert(
        string  $ident,
        bool    $isMember,
        ?string $hexUserId,
        string  $nick,
        ?string $color,
        int     $role,
        int     $status,
        ?string $packedIp,
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO chat_presence (ident, is_member, user_id, nick, color, role, status, ip, joined_at, last_seen)
                 VALUES (:ident, :ism, UNHEX(:uid), :nick, :color, :role, :status, :ip, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    nick = VALUES(nick), color = VALUES(color), role = VALUES(role),
                    status = VALUES(status), ip = VALUES(ip), last_seen = NOW()'
            );
            $stmt->execute([
                ':ident'  => $ident,
                ':ism'    => $isMember ? 1 : 0,
                ':uid'    => $hexUserId,
                ':nick'   => $nick,
                ':color'  => $color,
                ':role'   => $role,
                ':status' => $status,
                ':ip'     => $packedIp,
            ]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function touch(string $ident): Result
    {
        try {
            $this->pdo->prepare('UPDATE chat_presence SET last_seen = NOW() WHERE ident = :id')
                ->execute([':id' => $ident]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<array<string,mixed>|null> */
    public function find(string $ident): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT ident, is_member, LOWER(HEX(user_id)) AS user_id, nick, color, role, status,
                        INET6_NTOA(ip) AS ip_str, UNIX_TIMESTAMP(last_seen) AS last_ts
                   FROM chat_presence WHERE ident = :id'
            );
            $stmt->execute([':id' => $ident]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) { return Result::ok(null); }
            /** @var array<string,mixed> $row */
            return Result::ok($row);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Active users seen since $cutoff, staff first then alphabetical.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function online(string $cutoff, bool $includeHidden = false): Result
    {
        try {
            // Incognito users are excluded for everyone EXCEPT staff (who pass
            // includeHidden=true) so moderators can still see and act on them.
            $hide = $includeHidden ? '' : ' AND COALESCE(s.incognito, 0) = 0';
            $stmt = $this->pdo->prepare(
                'SELECT p.ident, p.is_member, LOWER(HEX(p.user_id)) AS user_id, p.nick, p.color, p.role, p.status,
                        UNIX_TIMESTAMP(p.last_seen) AS last_ts, COALESCE(s.incognito, 0) AS incognito
                   FROM chat_presence p
                   LEFT JOIN chat_settings s ON s.ident = p.ident
                  WHERE p.status = 1 AND p.last_seen > :cutoff' . $hide . '
                  ORDER BY p.role DESC, p.nick ASC'
            );
            $stmt->execute([':cutoff' => $cutoff]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<int> */
    public function countOnline(string $cutoff): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM chat_presence p
                   LEFT JOIN chat_settings s ON s.ident = p.ident
                  WHERE p.status = 1 AND p.last_seen > :cutoff AND COALESCE(s.incognito, 0) = 0'
            );
            $stmt->execute([':cutoff' => $cutoff]);
            return Result::ok((int) $stmt->fetchColumn());
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Guests awaiting moderator approval (status = 3), oldest request first.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function pending(string $cutoff): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT ident, is_member, LOWER(HEX(user_id)) AS user_id, nick, color, role, status,
                        INET6_NTOA(ip) AS ip_str, UNIX_TIMESTAMP(last_seen) AS last_ts
                   FROM chat_presence
                  WHERE status = 3 AND last_seen > :cutoff
                  ORDER BY joined_at ASC'
            );
            $stmt->execute([':cutoff' => $cutoff]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Every live presence (any status) seen since $cutoff — the moderator
     * "active sessions" view; carries IP + join/last-seen for accountability.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function allSessions(string $cutoff): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT ident, is_member, LOWER(HEX(user_id)) AS user_id, nick, color, role, status,
                        INET6_NTOA(ip) AS ip_str, UNIX_TIMESTAMP(joined_at) AS joined_ts,
                        UNIX_TIMESTAMP(last_seen) AS last_ts
                   FROM chat_presence
                  WHERE last_seen > :cutoff
                  ORDER BY status ASC, role DESC, nick ASC'
            );
            $stmt->execute([':cutoff' => $cutoff]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<int> presences removed (idle beyond $cutoff) */
    public function logoutInactive(string $cutoff): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM chat_presence WHERE last_seen < :cutoff');
            $stmt->execute([':cutoff' => $cutoff]);
            return Result::ok($stmt->rowCount());
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<int> guest presences flipped to KICKED */
    public function kickGuests(): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE chat_presence SET status = 2, last_seen = NOW() WHERE is_member = 0 AND status <> 2'
            );
            $stmt->execute();
            return Result::ok($stmt->rowCount());
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Is $nick currently held by a live presence other than $exceptIdent?
     *
     * @return Result<bool>
     */
    public function nickTaken(string $nick, string $exceptIdent, string $cutoff): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM chat_presence
                  WHERE LOWER(nick) = LOWER(:nick) AND ident <> :ex AND last_seen > :cutoff LIMIT 1'
            );
            $stmt->execute([':nick' => $nick, ':ex' => $exceptIdent, ':cutoff' => $cutoff]);
            return Result::ok($stmt->fetch() !== false);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * A live presence currently using $nick (for PM target resolution).
     *
     * @return Result<array<string,mixed>|null>
     */
    public function findByNick(string $nick, string $cutoff): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT ident, is_member, LOWER(HEX(user_id)) AS user_id, nick, color, role, status,
                        INET6_NTOA(ip) AS ip_str
                   FROM chat_presence
                  WHERE LOWER(nick) = LOWER(:nick) AND last_seen > :cutoff
                  ORDER BY last_seen DESC LIMIT 1'
            );
            $stmt->execute([':nick' => $nick, ':cutoff' => $cutoff]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) { return Result::ok(null); }
            /** @var array<string,mixed> $row */
            return Result::ok($row);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function setStatus(string $ident, int $status): Result
    {
        try {
            $this->pdo->prepare('UPDATE chat_presence SET status = :s, last_seen = NOW() WHERE ident = :id')
                ->execute([':s' => $status, ':id' => $ident]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function remove(string $ident): Result
    {
        try {
            $this->pdo->prepare('DELETE FROM chat_presence WHERE ident = :id')->execute([':id' => $ident]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<int> rows removed */
    public function gcStale(string $cutoff): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM chat_presence WHERE last_seen < :cutoff');
            $stmt->execute([':cutoff' => $cutoff]);
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
