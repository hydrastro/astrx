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
        'id, slug, title, subtitle, description, banner, rules,
         LOWER(HEX(owner_user_id)) AS owner_user_id,
         active, nsfw, forced_anon, bbcode, flags_mode, poster_ids, lifecycle,
         bump_limit, image_limit, thread_limit, max_post_len, cooldown_secs, max_replies, sort_order';

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

    /**
     * Every board — active or not — for the admin management list.
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function all(): Result
    {
        try {
            $stmt = $this->pdo->query('SELECT ' . self::COLS . ' FROM board ORDER BY sort_order ASC, slug ASC');
            assert($stmt !== false);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($rows);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Create a board. The slug is UNIQUE, so a duplicate surfaces as a DB error
     * captured in the Result rather than a throw.
     *
     * @return Result<int> new board id
     */
    public function create(
        string $slug, string $title, string $subtitle, string $description,
        bool $active, bool $nsfw, bool $forcedAnon, bool $bbcode,
        int $cooldownSecs, int $maxReplies, int $threadLimit, int $maxPostLen
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO board
                    (slug, title, subtitle, description, active, nsfw, forced_anon, bbcode,
                     cooldown_secs, max_replies, thread_limit, max_post_len)
                 VALUES
                    (:slug, :title, :subtitle, :description, :active, :nsfw, :forced_anon, :bbcode,
                     :cooldown, :max_replies, :thread_limit, :max_post_len)'
            );
            $stmt->execute([
                ':slug' => $slug, ':title' => $title, ':subtitle' => $subtitle, ':description' => $description,
                ':active' => $active ? 1 : 0, ':nsfw' => $nsfw ? 1 : 0, ':forced_anon' => $forcedAnon ? 1 : 0,
                ':bbcode' => $bbcode ? 1 : 0, ':cooldown' => $cooldownSecs, ':max_replies' => $maxReplies,
                ':thread_limit' => $threadLimit, ':max_post_len' => $maxPostLen,
            ]);
            $raw = $this->pdo->lastInsertId();
            return Result::ok(is_numeric($raw) ? (int) $raw : 0);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Save a board's presentation: a site-relative banner image path and a
     * rules/info blurb. Separate from update()/saveModSettings() so it can be
     * set from the admin board form without disturbing the other fields.
     *
     * @return Result<bool>
     */
    public function updatePresentation(int $id, string $banner, string $rules): Result
    {
        try {
            $this->pdo->prepare('UPDATE board SET banner = :banner, rules = :rules WHERE id = :id')
                ->execute([':banner' => $banner, ':rules' => $rules, ':id' => $id]);
            return Result::ok(true);
        } catch (PDOException $e) {
            return $this->err($e);
        }
    }

    /**
     * Update a board. The slug is immutable here — changing it would break every
     * existing thread URL and the seeded navbar link — so it is not updatable.
     *
     * @return Result<bool>
     */
    public function update(
        int $id, string $title, string $subtitle, string $description,
        bool $active, bool $nsfw, bool $forcedAnon, bool $bbcode,
        int $cooldownSecs, int $maxReplies, int $threadLimit, int $maxPostLen
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE board SET
                    title = :title, subtitle = :subtitle, description = :description,
                    active = :active, nsfw = :nsfw, forced_anon = :forced_anon, bbcode = :bbcode,
                    cooldown_secs = :cooldown, max_replies = :max_replies,
                    thread_limit = :thread_limit, max_post_len = :max_post_len
                  WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id, ':title' => $title, ':subtitle' => $subtitle, ':description' => $description,
                ':active' => $active ? 1 : 0, ':nsfw' => $nsfw ? 1 : 0, ':forced_anon' => $forcedAnon ? 1 : 0,
                ':bbcode' => $bbcode ? 1 : 0, ':cooldown' => $cooldownSecs, ':max_replies' => $maxReplies,
                ':thread_limit' => $threadLimit, ':max_post_len' => $maxPostLen,
            ]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Save the settings the per-board moderation UI exposes. This is a superset
     * of update() in the fields it covers (poster_ids, flags_mode, lifecycle,
     * bump_limit, image_limit) but deliberately leaves `active`, `max_replies`
     * and the immutable `slug` untouched — the mod surface does not manage them.
     * `flags_mode` and `lifecycle` are ENUMs the caller validates against their
     * allowlist before calling.
     *
     * @return Result<bool>
     */
    public function saveModSettings(
        int    $id,
        string $title,
        string $subtitle,
        string $description,
        bool   $nsfw,
        bool   $forcedAnon,
        bool   $bbcode,
        bool   $posterIds,
        string $flagsMode,
        string $lifecycle,
        int    $bumpLimit,
        int    $imageLimit,
        int    $threadLimit,
        int    $maxPostLen,
        int    $cooldownSecs,
    ): Result {
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE board SET
                    title = :title, subtitle = :subtitle, description = :description,
                    nsfw = :nsfw, forced_anon = :forced_anon, bbcode = :bbcode,
                    poster_ids = :poster_ids, flags_mode = :flags_mode, lifecycle = :lifecycle,
                    bump_limit = :bump_limit, image_limit = :image_limit,
                    thread_limit = :thread_limit, max_post_len = :max_post_len,
                    cooldown_secs = :cooldown
                  WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id, ':title' => $title, ':subtitle' => $subtitle, ':description' => $description,
                ':nsfw' => $nsfw ? 1 : 0, ':forced_anon' => $forcedAnon ? 1 : 0, ':bbcode' => $bbcode ? 1 : 0,
                ':poster_ids' => $posterIds ? 1 : 0, ':flags_mode' => $flagsMode, ':lifecycle' => $lifecycle,
                ':bump_limit' => $bumpLimit, ':image_limit' => $imageLimit, ':thread_limit' => $threadLimit,
                ':max_post_len' => $maxPostLen, ':cooldown' => $cooldownSecs,
            ]);
            return Result::ok(true);
        } catch (PDOException $e) { return $this->err($e); }
    }

    /**
     * Delete a board. Threads, posts and image rows cascade away via their FKs
     * (the uploaded image FILES are left for a separate sweep).
     *
     * @return Result<bool>
     */
    public function delete(int $id): Result
    {
        try {
            $this->pdo->prepare('DELETE FROM board WHERE id = :id')->execute([':id' => $id]);
            return Result::ok(true);
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
