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
 * Data-access for `board_post`. Post numbers (`no`) are allocated by
 * BoardRepository::nextPostNo(); this repository only persists and reads.
 * `no` is backticked everywhere — it is a MariaDB reserved word.
 */
final class PostRepository
{
    public function __construct(private readonly PDO $pdo) {}

    private const COLS =
        'id, thread_id, board_id, `no`, is_op, name, tripcode, capcode, poster_id,
         flag_code, subject, body_html, LOWER(HEX(user_id)) AS user_id, sage, banned,
         UNIX_TIMESTAMP(created_at) AS created_ts';

    // Absolute ceiling on posts loaded for one thread view — defence-in-depth so
    // a thread that somehow grows past its reply cap still renders in bounded
    // memory instead of loading every row at once.
    private const MAX_THREAD_POSTS = 5000;

    /** @return Result<int> */
    public function create(PostDraft $d): Result
    {
        try {
            $uid = ($d->hexUserId !== null && $d->hexUserId !== '')
                ? (hex2bin($d->hexUserId) ?: null)
                : null;
            $this->pdo->prepare(
                'INSERT INTO board_post
                    (thread_id, board_id, `no`, is_op, name, tripcode, capcode, poster_id,
                     flag_code, subject, body_raw, body_html, user_id, ip, poster_key,
                     delete_pw_hash, sage)
                 VALUES
                    (:tid, :bid, :no, :op, :name, :trip, :cap, :pid,
                     :flag, :subj, :raw, :html, :uid, :ip, :pkey,
                     :dpw, :sage)'
            )->execute([
                ':tid'  => $d->threadId,
                ':bid'  => $d->boardId,
                ':no'   => $d->no,
                ':op'   => $d->isOp ? 1 : 0,
                ':name' => $d->name,
                ':trip' => $d->tripcode,
                ':cap'  => $d->capcode,
                ':pid'  => $d->posterId,
                ':flag' => $d->flagCode,
                ':subj' => $d->subject,
                ':raw'  => $d->bodyRaw,
                ':html' => $d->bodyHtml,
                ':uid'  => $uid,
                ':ip'   => $d->packedIp,
                ':pkey' => $d->posterKey,
                ':dpw'  => $d->deletePwHash,
                ':sage' => $d->sage ? 1 : 0,
            ]);
            $raw = $this->pdo->lastInsertId();
            return Result::ok(is_numeric($raw) ? (int) $raw : 0);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Every post in a thread, oldest first (OP then replies).
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function forThread(int $threadId): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT ' . self::COLS . ' FROM board_post WHERE thread_id = :t ORDER BY id ASC LIMIT :lim'
            );
            $stmt->bindValue(':t', $threadId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', self::MAX_THREAD_POSTS, PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * The OP post for each of the given thread ids, keyed by thread_id
     * (for catalog / index rendering).
     *
     * @param list<int> $threadIds
     * @return Result<array<int,array<string,mixed>>>
     */
    public function opsForThreads(array $threadIds): Result
    {
        if ($threadIds === []) {
            return Result::ok([]);
        }
        try {
            $place = implode(',', array_fill(0, count($threadIds), '?'));
            $stmt  = $this->pdo->prepare(
                'SELECT ' . self::COLS . " FROM board_post
                  WHERE is_op = 1 AND thread_id IN ($place)"
            );
            $stmt->execute(array_values($threadIds));
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $row) {
                $tid = is_numeric($row['thread_id'] ?? null) ? (int) $row['thread_id'] : 0;
                $out[$tid] = $row;
            }
            return Result::ok($out);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * The last $n non-OP replies in a thread, oldest first (index preview).
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function previewReplies(int $threadId, int $n): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM (
                    SELECT ' . self::COLS . ' FROM board_post
                     WHERE thread_id = :t AND is_op = 0
                     ORDER BY id DESC LIMIT :n
                 ) sub ORDER BY sub.id ASC'
            );
            $stmt->bindValue(':t', $threadId, PDO::PARAM_INT);
            $stmt->bindValue(':n', max(0, $n), PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Unix timestamp of this poster_key's most recent post on the board (0 = none).
     * Backs the per-poster post cooldown; uses idx_post_poster (board_id, poster_key).
     *
     * @return Result<int>
     */
    public function lastPostAtByPosterKey(int $boardId, string $posterKey): Result
    {
        if ($posterKey === '') {
            return Result::ok(0);
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT UNIX_TIMESTAMP(MAX(created_at)) FROM board_post
                  WHERE board_id = :b AND poster_key = :k'
            );
            $stmt->execute([':b' => $boardId, ':k' => $posterKey]);
            $v = $stmt->fetchColumn();
            return Result::ok(is_numeric($v) ? (int) $v : 0);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Map each given hex user id to that user's `type` (UserGroup value), for
     * colouring post-author names by role. Unknown ids are simply absent from the
     * result. One query for the whole page — no per-post lookups.
     *
     * @param list<string> $hexIds
     * @return Result<array<string,int>>
     */
    public function typesByUserIds(array $hexIds): Result
    {
        $hexIds = array_values(array_unique(array_filter($hexIds, static fn (string $h): bool => $h !== '')));
        if ($hexIds === []) {
            return Result::ok([]);
        }
        try {
            $place = implode(',', array_fill(0, count($hexIds), 'UNHEX(?)'));
            $stmt  = $this->pdo->prepare("SELECT LOWER(HEX(id)) AS id, type FROM `user` WHERE id IN ($place)");
            $stmt->execute($hexIds);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $row) {
                $hex  = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
                $type = is_numeric($row['type'] ?? null) ? (int) $row['type'] : 0;
                if ($hex !== '') { $out[$hex] = $type; }
            }
            return Result::ok($out);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /** @return Result<array<string,mixed>|null> */
    public function byId(int $id): Result
    {
        return $this->one('id = :k', [':k' => $id]);
    }

    /**
     * Resolve a `>>no` reference within a board.
     *
     * @return Result<array<string,mixed>|null>
     */
    public function byBoardNo(int $boardId, int $no): Result
    {
        return $this->one('board_id = :b AND `no` = :k', [':b' => $boardId, ':k' => $no]);
    }

    /** @return Result<bool> */
    public function delete(int $id): Result
    {
        try {
            $this->pdo->prepare('DELETE FROM board_post WHERE id = :id')->execute([':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * The packed (inet_pton) IP stored for a post, or null when none is on
     * record (the onion default stores no IP). Returned as the raw VARBINARY
     * bytes, ready to feed straight into a ban's `ip` column.
     *
     * @return Result<string|null>
     */
    public function packedIpById(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT ip FROM board_post WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $v = $stmt->fetchColumn();
            return Result::ok(is_string($v) && $v !== '' ? $v : null);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * The newest posts on a board, newest first — the source rows for the Atom
     * feed. `no`, subject, body_html, thread_id and created_ts (all in COLS) are
     * enough to render a feed entry and link it back to its thread.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function newestForBoard(int $boardId, int $limit): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT ' . self::COLS . ' FROM board_post
                  WHERE board_id = :b
                  ORDER BY id DESC LIMIT :lim'
            );
            $stmt->bindValue(':b', $boardId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Substring search over a post's raw body and subject, newest first, joined
     * to the (active) board for its slug so each hit can link to its thread.
     *
     * The user term is matched as a LIKE substring: LIKE metacharacters (%, _
     * and the escape char itself) are escaped so they match literally, and the
     * wrapped `%term%` is ALWAYS bound — never interpolated — so there is no SQL
     * injection surface. An optional board id narrows the search to one board.
     * Two distinct placeholders carry the same value because native prepares do
     * not allow reusing one named placeholder twice.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function search(string $query, ?int $boardId, int $limit): Result
    {
        try {
            // Escape the backslash first, then the LIKE wildcards, so a user
            // searching for "50%" or "a_b" matches those literally under ESCAPE '\'.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $like    = '%' . $escaped . '%';

            $sql = "SELECT p.id, p.thread_id, p.board_id, p.`no`, p.is_op, p.subject,
                           p.body_html, UNIX_TIMESTAMP(p.created_at) AS created_ts,
                           b.slug AS board_slug
                      FROM board_post p
                      JOIN board b ON b.id = p.board_id AND b.active = 1
                     WHERE (p.body_raw LIKE :q ESCAPE '\\\\' OR p.subject LIKE :q2 ESCAPE '\\\\')";
            if ($boardId !== null) {
                $sql .= ' AND p.board_id = :bid';
            }
            $sql .= ' ORDER BY p.id DESC LIMIT :lim';

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':q',  $like);
            $stmt->bindValue(':q2', $like);
            if ($boardId !== null) {
                $stmt->bindValue(':bid', $boardId, PDO::PARAM_INT);
            }
            $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * @param array<string,int> $params
     * @return Result<array<string,mixed>|null>
     */
    private function one(string $where, array $params): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT ' . self::COLS . ' FROM board_post WHERE ' . $where . ' LIMIT 1');
            $stmt->execute($params);
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

    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new ImageboardDbDiagnostic(
            'astrx.imageboard/db_error', DiagnosticLevel::ERROR, $e->getMessage()
        )));
    }
}
