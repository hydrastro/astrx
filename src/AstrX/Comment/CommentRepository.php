<?php
declare(strict_types=1);

namespace AstrX\Comment;

use AstrX\Comment\Diagnostic\CommentDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use PDO;
use PDOException;
use AstrX\Result\DiagnosticLevel;

/**
 * Pure data-access for the `comment` table.
 *
 * IDs: comment.id is INT AUTO_INCREMENT.
 * User IDs: BINARY(16) — use UNHEX() / LOWER(HEX()) in queries.
 * IP: VARBINARY(16) — stored with inet_pton(), read with inet_ntop().
 */
final class CommentRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Fetch visible comments for a page, ordered by date.
     * Returns flat list; CommentService handles tree assembly.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function fetchForPage(int $pageId, bool $descending = false, int $limit = 0, int $offset = 0, ?int $itemId = null): Result
    {
        $order = $descending ? 'DESC' : 'ASC';
        try {
            if ($limit > 0) {
                $stmt = $this->pdo->prepare(
                    "SELECT c.id, c.page_id, c.item_id, LOWER(HEX(c.user_id)) AS user_id,
                            c.name, c.email, c.content, c.reply_to,
                            c.flagged, c.hidden, c.created_at,
                            COALESCE(u.display_name, u.username) AS user_display_name,
                            u.avatar AS user_has_avatar
                       FROM comment c LEFT JOIN user u ON u.id = c.user_id
                      WHERE c.page_id = :pid AND c.hidden = 0"
                    . ($itemId !== null ? " AND c.item_id = :item_id" : " AND c.item_id IS NULL")
                    . " ORDER BY c.created_at {$order} LIMIT :lim OFFSET :off"
                );
                $stmt->bindValue(':pid', $pageId, PDO::PARAM_INT);
                if ($itemId !== null) { $stmt->bindValue(':item_id', $itemId, PDO::PARAM_INT); }
                $stmt->bindValue(':lim', $limit,  PDO::PARAM_INT);
                $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT c.id, c.page_id, c.item_id, LOWER(HEX(c.user_id)) AS user_id,
                            c.name, c.email, c.content, c.reply_to,
                            c.flagged, c.hidden, c.created_at,
                            COALESCE(u.display_name, u.username) AS user_display_name,
                            u.avatar AS user_has_avatar
                       FROM comment c LEFT JOIN user u ON u.id = c.user_id
                      WHERE c.page_id = :pid AND c.hidden = 0"
                    . ($itemId !== null ? " AND c.item_id = :item_id" : " AND c.item_id IS NULL")
                    . " ORDER BY c.created_at {$order}"
                );
                $stmt->bindValue(':pid', $pageId, PDO::PARAM_INT);
                if ($itemId !== null) { $stmt->bindValue(':item_id', $itemId, PDO::PARAM_INT); }
            }
            $stmt->execute();
            /** @var list<array<string,mixed>> $_rows */ $_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Result::ok($_rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> total visible comment count for a page */
    public function countForPage(int $pageId, ?int $itemId = null): Result
    {
        try {
            $sql = 'SELECT COUNT(id) FROM comment WHERE page_id = :pid AND hidden = 0'
                   . ($itemId !== null ? ' AND item_id = :item_id' : ' AND item_id IS NULL');
            $stmt = $this->pdo->prepare($sql);
            $params = [':pid' => $pageId];
            if ($itemId !== null) { $params[':item_id'] = $itemId; }
            $stmt->execute($params);
            return Result::ok((int) $stmt->fetchColumn());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<array<string,mixed>|null> */
    public function findById(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, page_id, LOWER(HEX(user_id)) AS user_id,
                        name, email, content, reply_to, flagged, hidden, created_at
                   FROM comment WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return Result::ok($row !== false ? $row : null);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Admin: fetch all comments with optional filters.
     * @param array<string,mixed> $filters  e.g. ['page_id' => 1, 'flagged' => 1]
     * @return Result<list<array<string,mixed>>>
     */
    public function fetchAll(array $filters = [], bool $descending = true): Result
    {
        $order  = $descending ? 'DESC' : 'ASC';
        $where  = [];
        $params = [];
        $allowed = ['page_id', 'user_id', 'hidden', 'flagged'];
        foreach ($filters as $col => $val) {
            if (!in_array($col, $allowed, true)) {
                continue;
            }
            $where[]       = "{$col} = :{$col}";
            $params[":{$col}"] = $val;
        }
        $sql = "SELECT id, page_id, LOWER(HEX(user_id)) AS user_id,
                       name, email, content, reply_to, flagged, hidden, created_at
                  FROM comment"
               . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
               . " ORDER BY created_at {$order}";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            /** @var list<array<string,mixed>> $_rows */ $_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Result::ok($_rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new comment. Returns the new auto-increment id.
     * $ip is a packed binary string from inet_pton() or null.
     *
     * @return Result<int>
     */
    public function create(
        int     $pageId,
        ?string $hexUserId,
        ?string $name,
        ?string $email,
        string  $content,
        ?int    $replyTo,
        ?string $ip,       // raw output of inet_pton()
        ?int    $itemId = null,
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO comment
                    (page_id, item_id, user_id, name, email, content, reply_to, ip)
                 VALUES
                    (:page, :item_id, UNHEX(:uid), :name, :email, :content, :reply, :ip)'
            );
            $stmt->execute([
                               ':page'    => $pageId,
                               ':item_id' => $itemId,
                               ':uid'     => $hexUserId,
                               ':name'    => $name,
                               ':email'   => $email,
                               ':content' => $content,
                               ':reply'   => $replyTo,
                               ':ip'      => $ip,
                           ]);
            return Result::ok((int) $this->pdo->lastInsertId());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
    public function update(int $id, string $content, string $name,
        ?string $email, ?int $replyTo, bool $hidden, bool $flagged): Result
    {
        try {
            $this->pdo->prepare(
                'UPDATE comment
                    SET content = :content, name = :name, email = :email,
                        reply_to = :reply_to, hidden = :hidden, flagged = :flagged
                  WHERE id = :id'
            )->execute([':content' => $content, ':name' => $name,
                        ':email' => $email, ':reply_to' => $replyTo,
                        ':hidden' => (int) $hidden,
                        ':flagged' => (int) $flagged, ':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
    public function setHidden(int $id, bool $hidden): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE comment SET hidden = :h WHERE id = :id'
            );
            $stmt->execute([':h' => (int) $hidden, ':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
    public function setFlagged(int $id, bool $flagged): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE comment SET flagged = :f WHERE id = :id'
            );
            $stmt->execute([':f' => (int) $flagged, ':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<bool> */
    public function delete(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('DELETE FROM comment WHERE id = :id');
            $stmt->execute([':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<int> rows affected */
    /**
     * @param array<string,mixed> $filters
     * @return Result<int>
     */
    public function bulkSetHidden(array $filters, bool $hidden): Result
    {
        if ($filters === []) {
            return Result::ok(0);
        }
        $where  = [];
        $params = [':h' => (int) $hidden];
        foreach ($filters as $col => $val) {
            $where[]       = "{$col} = :{$col}";
            $params[":{$col}"] = $val;
        }
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE comment SET hidden = :h WHERE ' . implode(' AND ', $where)
            );
            $stmt->execute($params);
            return Result::ok($stmt->rowCount());
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    // -------------------------------------------------------------------------

    /** @return Result<?int> */
    public function lastCommentTime(?string $hexUserId, ?string $packedIp): Result
    {
        try {
            if ($hexUserId !== null) {
                $stmt = $this->pdo->prepare(
                    'SELECT UNIX_TIMESTAMP(created_at) AS ts FROM comment
                      WHERE user_id = UNHEX(:uid) ORDER BY created_at DESC LIMIT 1'
                );
                $stmt->execute([':uid' => $hexUserId]);
            } elseif ($packedIp !== null) {
                $stmt = $this->pdo->prepare(
                    'SELECT UNIX_TIMESTAMP(created_at) AS ts FROM comment
                      WHERE ip = :ip ORDER BY created_at DESC LIMIT 1'
                );
                $stmt->execute([':ip' => $packedIp]);
            } else {
                return Result::ok(null);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return Result::ok($row !== false ? (int) $row['ts'] : null);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function isMuted(?string $hexUserId, ?string $packedIp, int $pageId): Result
    {
        try {
            $now = date('Y-m-d H:i:s');
            $conds = []; $params = [':now' => $now, ':page_id' => $pageId];
            if ($hexUserId !== null) {
                $conds[] = '(user_id = UNHEX(:uid) AND (page_id IS NULL OR page_id = :page_id))';
                $params[':uid'] = $hexUserId;
            }
            if ($packedIp !== null) {
                $conds[] = '(ip = :ip AND (page_id IS NULL OR page_id = :page_id2))';
                $params[':ip'] = $packedIp; $params[':page_id2'] = $pageId;
            }
            if ($conds === []) { return Result::ok(false); }
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM mute WHERE expires_at > :now AND (' . implode(' OR ', $conds) . ') LIMIT 1'
            );
            $stmt->execute($params);
            return Result::ok($stmt->fetch() !== false);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function addMute(?string $hexUserId, ?string $packedIp, ?int $pageId, int $durationSecs): Result
    {
        try {
            $expires = date('Y-m-d H:i:s', time() + $durationSecs);
            $this->pdo->prepare(
                'INSERT INTO mute (user_id, ip, page_id, expires_at) VALUES (UNHEX(:uid), :ip, :pid, :exp)'
            )->execute([':uid' => $hexUserId, ':ip' => $packedIp, ':pid' => $pageId, ':exp' => $expires]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<list<array<string,mixed>>> */
    public function listMutes(): Result
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT m.id, LOWER(HEX(m.user_id)) AS user_id, m.ip, m.page_id,
                        m.expires_at, u.username
                   FROM mute m LEFT JOIN user u ON u.id = m.user_id
                  WHERE m.expires_at > NOW() ORDER BY m.expires_at DESC"
            );
            assert($stmt !== false);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['ip_display'] = $row['ip'] !== null
                    ? (inet_ntop($row['ip']) ?: bin2hex($row['ip'])) : null;
            }
            unset($row);
            return Result::ok($rows);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<bool> */
    public function deleteMute(int $id): Result
    {
        try {
            $this->pdo->prepare('DELETE FROM mute WHERE id = :id')->execute([':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }



    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new CommentDbDiagnostic(
                                                     'astrx.comment/db_error', DiagnosticLevel::ERROR,
                                                     $e->getMessage(),
                                                 )));
    }
}
