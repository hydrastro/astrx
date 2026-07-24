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
 * Data-access for `board` rows: per-board settings and the atomic per-board
 * post counter. Boards are the routing/config unit; threads and posts hang off
 * them (see ThreadRepository / PostRepository).
 */
final class BoardRepository
{
    public function __construct(private readonly PDO $pdo) {}

    private const COLS =
        'id, slug, title, subtitle, description, LOWER(HEX(owner_user_id)) AS owner_user_id,
         active, nsfw, forced_anon, bbcode, flags_mode, poster_ids, lifecycle,
         bump_limit, image_limit, thread_limit, max_post_len, cooldown_secs, sort_order';

    /**
     * Look up an active board by its URL slug.
     *
     * @return Result<array<string,mixed>|null>
     */
    public function bySlug(string $slug): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT ' . self::COLS . ' FROM board WHERE slug = :s AND active = 1');
            $stmt->execute([':s' => $slug]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) { return Result::ok(null); }
            /** @var array<string,mixed> $row */
            return Result::ok($row);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Look up a board by id (active or not — for admin).
     *
     * @return Result<array<string,mixed>|null>
     */
    public function byId(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT ' . self::COLS . ' FROM board WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) { return Result::ok(null); }
            /** @var array<string,mixed> $row */
            return Result::ok($row);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Every active board in display order (board list / navbar).
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function listActive(): Result
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT ' . self::COLS . ' FROM board WHERE active = 1 ORDER BY sort_order ASC, slug ASC'
            );
            assert($stmt !== false);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Atomically allocate the next per-board post number (the `no`). The
     * LAST_INSERT_ID(expr) idiom makes the increment and read a single atomic
     * step, so concurrent posters never collide on a number.
     *
     * @return Result<int>
     */
    public function nextPostNo(int $boardId): Result
    {
        try {
            $this->pdo->prepare('UPDATE board SET post_seq = LAST_INSERT_ID(post_seq + 1) WHERE id = :id')
                ->execute([':id' => $boardId]);
            $raw = $this->pdo->lastInsertId();
            $no  = is_numeric($raw) ? (int) $raw : 0;
            return Result::ok($no);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /** @return Result<never> */
    private function err(PDOException $e): Result
    {
        return Result::err(null, Diagnostics::of(new ImageboardDbDiagnostic(
            'astrx.imageboard/db_error', DiagnosticLevel::ERROR, $e->getMessage()
        )));
    }
}
