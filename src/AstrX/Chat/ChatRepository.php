<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Chat\Diagnostic\ChatDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;
use PDOException;
use AstrX\Result\DiagnosticLevel;

/**
 * Pure data-access for the `chat_room` + `chat_message` tables.
 *
 * IDs: chat_room.id / chat_message.id are INT AUTO_INCREMENT.
 * User IDs: BINARY(16) — use UNHEX() / LOWER(HEX()) in queries.
 * IP: VARBINARY(16) — stored with inet_pton(), read with inet_ntop().
 */
final class ChatRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Fetch all active rooms, ordered for display.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function fetchRooms(): Result
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT id, name, topic, min_level, active, sort_order
                   FROM chat_room WHERE active = 1 ORDER BY sort_order, name'
            );
            assert($stmt !== false);
            /** @var list<array<string,mixed>> $rows */ $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<array<string,mixed>|null> */
    public function findRoomById(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, name, min_level, active, sort_order
                   FROM chat_room WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) { return Result::ok(null); }
            /** @var array<string,mixed> $fetched */
            return Result::ok($fetched);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Fetch the visible (non-expired) messages for a room, oldest first.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function fetchMessages(int $roomId, int $limit): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT m.id, LOWER(HEX(m.user_id)) AS user_id, m.nick, m.color, m.content, m.type,
                        UNIX_TIMESTAMP(m.created_at) AS created_ts, m.created_at,
                        u.type AS user_type,
                        COALESCE(u.display_name, u.username) AS user_display_name,
                        u.avatar AS user_has_avatar
                   FROM chat_message m LEFT JOIN user u ON u.id = m.user_id
                  WHERE m.room_id = :room AND m.expires_at > NOW()
                  ORDER BY m.created_at ASC, m.id ASC LIMIT :lim"
            );
            $stmt->bindValue(':room', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':lim',  $limit,  PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */ $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<array<string,mixed>|null> */
    public function findMessageById(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT m.id, LOWER(HEX(m.user_id)) AS user_id, m.room_id, m.content,
                        u.type AS user_type
                   FROM chat_message m
                   LEFT JOIN user u ON u.id = m.user_id
                  WHERE m.id = :id'
            );
            $stmt->execute([':id' => $id]);
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) { return Result::ok(null); }
            /** @var array<string,mixed> $fetched */
            return Result::ok($fetched);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new message. Returns the new auto-increment id.
     * $packedIp is a packed binary string from inet_pton() or null.
     *
     * @return Result<int>
     */
    public function create(
        int     $roomId,
        ?string $hexUserId,
        ?string $nick,
        ?string $color,
        string  $content,
        string  $expiresAt,
        ?string $packedIp,   // raw output of inet_pton()
        string  $type = 'user',
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO chat_message
                    (room_id, user_id, nick, color, content, expires_at, ip, type)
                 VALUES
                    (:room, UNHEX(:uid), :nick, :color, :content, :exp, :ip, :type)'
            );
            $stmt->execute([
                               ':room'    => $roomId,
                               ':uid'     => $hexUserId,
                               ':nick'    => $nick,
                               ':color'   => $color,
                               ':content' => $content,
                               ':exp'     => $expiresAt,
                               ':ip'      => $packedIp,
                               ':type'    => $type,
                           ]);
            return Result::ok((int) $this->pdo->lastInsertId());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
    public function deleteMessage(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM chat_message WHERE id = :id');
            $stmt->execute([':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> rows affected */
    public function deleteRoomMessages(int $roomId): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM chat_message WHERE room_id = :room');
            $stmt->execute([':room' => $roomId]);
            return Result::ok($stmt->rowCount());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Delete every message authored under $nick — a guest's stored nick, or a
     * member whose username/display_name matches. Single-room, so unscoped.
     * (Native prepares reject a name reused across placeholders, hence :n1..:n3.)
     *
     * @return Result<int> rows removed
     */
    public function deleteByNick(string $nick): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE m FROM chat_message m
                    LEFT JOIN `user` u ON u.id = m.user_id
                  WHERE m.nick = :n1 OR u.username = :n2 OR u.display_name = :n3'
            );
            $stmt->execute([':n1' => $nick, ':n2' => $nick, ':n3' => $nick]);
            return Result::ok($stmt->rowCount());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
    public function setRoomTopic(int $roomId, string $topic): Result
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE chat_room SET topic = :t WHERE id = :id');
            $stmt->execute([':t' => $topic, ':id' => $roomId]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> rows removed */
    public function gcExpired(): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM chat_message WHERE expires_at <= NOW()');
            $stmt->execute();
            return Result::ok($stmt->rowCount());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    // -------------------------------------------------------------------------

    /** @return Result<?int> */
    public function lastMessageTime(?string $hexUserId, ?string $packedIp): Result
    {
        try {
            if ($hexUserId !== null) {
                $stmt = $this->pdo->prepare(
                    'SELECT UNIX_TIMESTAMP(created_at) AS ts FROM chat_message
                      WHERE user_id = UNHEX(:uid) ORDER BY created_at DESC LIMIT 1'
                );
                $stmt->execute([':uid' => $hexUserId]);
            } elseif ($packedIp !== null) {
                $stmt = $this->pdo->prepare(
                    'SELECT UNIX_TIMESTAMP(created_at) AS ts FROM chat_message
                      WHERE ip = :ip ORDER BY created_at DESC LIMIT 1'
                );
                $stmt->execute([':ip' => $packedIp]);
            } else {
                return Result::ok(null);
            }
            $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) { return Result::ok(null); }
            /** @var array<string,mixed> $fetched */
            return Result::ok(is_int($fetched['ts']) ? $fetched['ts'] : 0);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function isMuted(?string $hexUserId, ?string $packedIp): Result
    {
        try {
            $now = date('Y-m-d H:i:s');
            $conds = []; $params = [':now' => $now];
            if ($hexUserId !== null) {
                $conds[] = '(user_id = UNHEX(:uid))';
                $params[':uid'] = $hexUserId;
            }
            if ($packedIp !== null) {
                $conds[] = '(ip = :ip)';
                $params[':ip'] = $packedIp;
            }
            if ($conds === []) { return Result::ok(false); }
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM mute WHERE expires_at > :now AND page_id IS NULL AND ('
                . implode(' OR ', $conds) . ') LIMIT 1'
            );
            $stmt->execute($params);
            return Result::ok($stmt->fetch() !== false);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function addMute(?string $hexUserId, ?string $packedIp, int $durationSecs): Result
    {
        try {
            $expires = date('Y-m-d H:i:s', time() + $durationSecs);
            $this->pdo->prepare(
                'INSERT INTO mute (user_id, ip, page_id, expires_at) VALUES (UNHEX(:uid), :ip, NULL, :exp)'
            )->execute([':uid' => $hexUserId, ':ip' => $packedIp, ':exp' => $expires]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    // -------------------------------------------------------------------------

    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new ChatDbDiagnostic(
                                                     'astrx.chat/db_error', DiagnosticLevel::ERROR,
                                                     $e->getMessage(),
                                                 )));
    }
}
