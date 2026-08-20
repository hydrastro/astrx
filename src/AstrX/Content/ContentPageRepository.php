<?php
declare(strict_types=1);

namespace AstrX\Content;

use AstrX\Content\Diagnostic\ContentDbDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use PDO;
use PDOException;

/**
 * Data access for the content module: the Markdown pages (`content_page`) and
 * their `[[wiki]]` link graph (`content_link`).
 *
 * Visibility model (R8):
 *   - `visible`     TINYINT  — the published/draft master toggle (0 = draft).
 *   - `visibility`  VARCHAR  — 'public' | 'unlisted' | 'private'.
 *   - `publish_at`  ?int     — unix ts; NULL = live immediately.
 *   - `expire_at`   ?int     — unix ts; NULL = never expires.
 * A page is "live" when visible=1 AND now within [publish_at, expire_at). The
 * PUBLIC listing (index/graph/sitemap) shows only live `public` pages, plus live
 * `private` pages to a logged-in viewer; `unlisted` pages are reachable only by
 * their direct URL. Non-live / non-viewable pages are admin-preview only. The
 * viewing policy itself lives in {@see ContentService}; the repo just applies the
 * SQL filters for the listing/graph/backlink sets.
 *
 * Saving a page re-extracts its outbound links and (re)resolves inbound links,
 * so `content_link.to_id` is always the resolved target id or NULL for a broken
 * link. All queries are bound; native prepares mean integer columns come back as
 * ints.
 *
 * @phpstan-type PageRow array{id:int,slug:string,title:string,body:string,visible:bool,visibility:string,publish_at:?int,expire_at:?int,updated_at:string}
 * @phpstan-type ListRow array{id:int,slug:string,title:string,visible:bool,visibility:string,publish_at:?int,expire_at:?int,updated_at:string}
 */
final class ContentPageRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return Result<?array{id:int,slug:string,title:string,body:string,visible:bool,visibility:string,publish_at:?int,expire_at:?int,updated_at:string}> */
    public function bySlug(string $slug): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM `content_page` WHERE `slug` = :s LIMIT 1');
            $stmt->bindValue(':s', $slug);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return Result::ok(null);
            }
            /** @var array<string,mixed> $row */
            return Result::ok($this->row($row));
        } catch (PDOException $e) {
            return Result::err(null, $this->diag($e));
        }
    }

    /** @return Result<?array{id:int,slug:string,title:string,body:string,visible:bool,visibility:string,publish_at:?int,expire_at:?int,updated_at:string}> */
    public function byId(int $id): Result
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM `content_page` WHERE `id` = :i LIMIT 1');
            $stmt->bindValue(':i', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return Result::ok(null);
            }
            /** @var array<string,mixed> $row */
            return Result::ok($this->row($row));
        } catch (PDOException $e) {
            return Result::err(null, $this->diag($e));
        }
    }

    /**
     * Every page, unfiltered — the admin list.
     *
     * @return Result<list<array{id:int,slug:string,title:string,visible:bool,visibility:string,publish_at:?int,expire_at:?int,updated_at:string}>>
     */
    public function allForAdmin(): Result
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT `id`, `slug`, `title`, `visible`, `visibility`, `publish_at`, `expire_at`, `updated_at`
                   FROM `content_page`
                  ORDER BY `title` = \'\', `title` ASC, `slug` ASC'
            );
            $out = [];
            if ($stmt !== false) {
                /** @var array<string,mixed> $r */
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = $this->listRow($r);
                }
            }
            return Result::ok($out);
        } catch (PDOException $e) {
            return Result::err([], $this->diag($e));
        }
    }

    /**
     * The PUBLIC listing set: live `public` pages, plus live `private` pages when
     * $includePrivate (a logged-in viewer). `unlisted` never appears here.
     *
     * @return Result<list<array{id:int,slug:string,title:string,visible:bool,visibility:string,publish_at:?int,expire_at:?int,updated_at:string}>>
     */
    public function listed(bool $includePrivate, int $now): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT `id`, `slug`, `title`, `visible`, `visibility`, `publish_at`, `expire_at`, `updated_at`
                   FROM `content_page`
                  WHERE `visible` = 1
                    AND (`publish_at` IS NULL OR `publish_at` <= :now)
                    AND (`expire_at`  IS NULL OR `expire_at`  >  :now2)
                    AND (`visibility` = \'public\' OR (:priv = 1 AND `visibility` = \'private\'))
                  ORDER BY `title` = \'\', `title` ASC, `slug` ASC'
            );
            $stmt->bindValue(':now',  $now, PDO::PARAM_INT);
            $stmt->bindValue(':now2', $now, PDO::PARAM_INT);
            $stmt->bindValue(':priv', $includePrivate ? 1 : 0, PDO::PARAM_INT);
            $stmt->execute();
            $out = [];
            /** @var array<string,mixed> $r */
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = $this->listRow($r);
            }
            return Result::ok($out);
        } catch (PDOException $e) {
            return Result::err([], $this->diag($e));
        }
    }

    /**
     * All slugs (any visibility) — used only to resolve `[[wiki]]` links, so a
     * link to an unlisted/private page still resolves rather than showing broken.
     *
     * @return Result<array<string,true>>
     */
    public function allSlugs(): Result
    {
        try {
            $stmt = $this->pdo->query('SELECT `slug` FROM `content_page`');
            $set  = [];
            if ($stmt !== false) {
                /** @var array<string,mixed> $r */
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $set[$this->s($r['slug'] ?? null)] = true;
                }
            }
            return Result::ok($set);
        } catch (PDOException $e) {
            return Result::err([], $this->diag($e));
        }
    }

    /**
     * Insert (id=0) or update a page, then rebuild its link graph. Returns the id.
     *
     * Runs in ONE transaction. It used to write the page row, then DELETE and
     * re-INSERT every outbound link in autocommit: a failure part-way through
     * left the page saved with a truncated link graph AND returned err, so the
     * admin was told the save had failed while half of it had in fact
     * committed. Either the page and its whole link graph land together, or
     * nothing does and the err is the truth.
     *
     * @return Result<int>
     */
    public function save(
        int $id,
        string $slug,
        string $title,
        string $body,
        bool $visible,
        string $visibility,
        ?int $publishAt,
        ?int $expireAt,
    ): Result {
        // An unrecognised visibility used to normalise to 'public' — the MOST
        // open of the three — so a typo, or a form field that simply failed to
        // post, PUBLISHED the page. Fall back to what the page already is, and
        // to 'private' for a page that does not exist yet, so a bad value can
        // only ever narrow the audience.
        if (!in_array($visibility, ['public', 'unlisted', 'private'], true)) {
            $visibility = $id > 0 ? $this->currentVisibility($id) : 'private';
        }

        $owned = false;
        try {
            // A caller may already have a transaction open; only manage our own.
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $owned = true;
            }

            $previousSlug = $id > 0 ? $this->currentSlug($id) : null;

            if ($id > 0) {
                $stmt = $this->pdo->prepare(
                    'UPDATE `content_page`
                        SET `slug`=:s, `title`=:t, `body`=:b, `visible`=:v,
                            `visibility`=:vis, `publish_at`=:pa, `expire_at`=:ea
                      WHERE `id`=:i'
                );
                $stmt->bindValue(':i', $id, PDO::PARAM_INT);
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO `content_page` (`slug`, `title`, `body`, `visible`, `visibility`, `publish_at`, `expire_at`)
                     VALUES (:s, :t, :b, :v, :vis, :pa, :ea)'
                );
            }
            $stmt->bindValue(':s', $slug);
            $stmt->bindValue(':t', $title);
            $stmt->bindValue(':b', $body);
            $stmt->bindValue(':v', $visible ? 1 : 0, PDO::PARAM_INT);
            $stmt->bindValue(':vis', $visibility);
            $stmt->bindValue(':pa', $publishAt, $publishAt === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':ea', $expireAt,  $expireAt  === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->execute();
            if ($id === 0) {
                $id = (int) $this->pdo->lastInsertId();
            }

            $this->rebuildOutboundLinks($id, $slug, Markdown::wikiTargets($body));

            // Renaming a slug leaves every inbound [[old-slug]] link pointing at
            // this page through a stale, still-non-NULL to_id. The broken-link
            // report keys on `to_id IS NULL`, so it reported those links clean
            // while the rendered page marked them broken and following one 404'd
            // — database and page permanently disagreeing, with no way to notice
            // from the admin UI. Unresolve them explicitly.
            if ($previousSlug !== null && $previousSlug !== $slug) {
                $orphan = $this->pdo->prepare(
                    'UPDATE `content_link` SET `to_id` = NULL WHERE `to_id` = :i AND `to_slug` <> :s'
                );
                $orphan->bindValue(':i', $id, PDO::PARAM_INT);
                $orphan->bindValue(':s', $slug);
                $orphan->execute();
            }

            // A new/renamed page resolves any inbound links that were waiting on it.
            $res = $this->pdo->prepare('UPDATE `content_link` SET `to_id`=:i WHERE `to_slug`=:s AND `to_id` IS NULL');
            $res->bindValue(':i', $id, PDO::PARAM_INT);
            $res->bindValue(':s', $slug);
            $res->execute();

            if ($owned) {
                $this->pdo->commit();
            }

            return Result::ok($id);
        } catch (PDOException $e) {
            if ($owned && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return Result::err(0, $this->diag($e));
        }
    }

    /** The slug currently stored for $id, or null when the row is gone. */
    private function currentSlug(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT `slug` FROM `content_page` WHERE `id` = :i LIMIT 1');
        $stmt->bindValue(':i', $id, PDO::PARAM_INT);
        $stmt->execute();
        $slug = $stmt->fetchColumn();
        $stmt->closeCursor();

        return is_string($slug) ? $slug : null;
    }

    /** The visibility currently stored for $id; the closed value when unknown. */
    private function currentVisibility(int $id): string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT `visibility` FROM `content_page` WHERE `id` = :i LIMIT 1');
            $stmt->bindValue(':i', $id, PDO::PARAM_INT);
            $stmt->execute();
            $current = $stmt->fetchColumn();
            $stmt->closeCursor();
        } catch (PDOException) {
            return 'private';
        }

        return is_scalar($current) && in_array((string) $current, ['public', 'unlisted', 'private'], true)
            ? (string) $current
            : 'private';
    }

    /** @return Result<bool> */
    public function delete(int $id): Result
    {
        try {
            // Inbound links to this page become broken (no FK cascade on to_id).
            $u = $this->pdo->prepare('UPDATE `content_link` SET `to_id` = NULL WHERE `to_id` = :i');
            $u->bindValue(':i', $id, PDO::PARAM_INT);
            $u->execute();
            // Outbound links cascade via the from_id foreign key.
            $d = $this->pdo->prepare('DELETE FROM `content_page` WHERE `id` = :i');
            $d->bindValue(':i', $id, PDO::PARAM_INT);
            $d->execute();
            return Result::ok(true);
        } catch (PDOException $e) {
            return Result::err(false, $this->diag($e));
        }
    }

    /**
     * Pages that link TO the given page ("what links here"). Only live PUBLIC
     * sources are shown, so a private/unlisted/draft linker is never leaked to a
     * public viewer.
     *
     * @return Result<list<array{slug:string,title:string}>>
     */
    public function backlinks(int $toId, int $now): Result
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT p.`slug`, p.`title`
                   FROM `content_link` l
                   JOIN `content_page` p ON p.`id` = l.`from_id`
                  WHERE l.`to_id` = :i AND l.`from_id` <> :i2
                    AND p.`visible` = 1 AND p.`visibility` = \'public\'
                    AND (p.`publish_at` IS NULL OR p.`publish_at` <= :now)
                    AND (p.`expire_at`  IS NULL OR p.`expire_at`  >  :now2)
                  ORDER BY p.`title` ASC'
            );
            $stmt->bindValue(':i', $toId, PDO::PARAM_INT);
            $stmt->bindValue(':i2', $toId, PDO::PARAM_INT);
            $stmt->bindValue(':now', $now, PDO::PARAM_INT);
            $stmt->bindValue(':now2', $now, PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return Result::ok($this->slugTitleRows($rows));
        } catch (PDOException $e) {
            return Result::err([], $this->diag($e));
        }
    }

    /**
     * Every unresolved link — the broken-link report.
     *
     * @return Result<list<array{from_slug:string,from_title:string,to_slug:string}>>
     */
    public function brokenLinks(): Result
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT p.`slug` AS from_slug, p.`title` AS from_title, l.`to_slug` AS to_slug
                   FROM `content_link` l
                   JOIN `content_page` p ON p.`id` = l.`from_id`
                  WHERE l.`to_id` IS NULL
                  ORDER BY p.`title` ASC, l.`to_slug` ASC'
            );
            $out = [];
            if ($stmt !== false) {
                /** @var array<string,mixed> $r */
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = [
                        'from_slug'  => $this->s($r['from_slug'] ?? null),
                        'from_title' => $this->s($r['from_title'] ?? null),
                        'to_slug'    => $this->s($r['to_slug'] ?? null),
                    ];
                }
            }
            return Result::ok($out);
        } catch (PDOException $e) {
            return Result::err([], $this->diag($e));
        }
    }

    /**
     * Graph data: the listed nodes (same policy as {@see listed()}) and the
     * resolved edges between them.
     *
     * @return Result<array{nodes:list<array{id:int,slug:string,title:string}>,edges:list<array{from:int,to:int}>}>
     */
    public function graph(bool $includePrivate, int $now): Result
    {
        try {
            $where = '`visible` = 1
                      AND (`publish_at` IS NULL OR `publish_at` <= :now)
                      AND (`expire_at`  IS NULL OR `expire_at`  >  :now2)
                      AND (`visibility` = \'public\' OR (:priv = 1 AND `visibility` = \'private\'))';

            $nodes = [];
            $nstmt = $this->pdo->prepare('SELECT `id`, `slug`, `title` FROM `content_page` WHERE ' . $where);
            $nstmt->bindValue(':now', $now, PDO::PARAM_INT);
            $nstmt->bindValue(':now2', $now, PDO::PARAM_INT);
            $nstmt->bindValue(':priv', $includePrivate ? 1 : 0, PDO::PARAM_INT);
            $nstmt->execute();
            /** @var array<string,mixed> $r */
            foreach ($nstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $nodes[] = [
                    'id'    => $this->i($r['id'] ?? null),
                    'slug'  => $this->s($r['slug'] ?? null),
                    'title' => $this->s($r['title'] ?? null),
                ];
            }

            $edges = [];
            $estmt = $this->pdo->prepare(
                'SELECT l.`from_id` AS f, l.`to_id` AS t
                   FROM `content_link` l
                   JOIN `content_page` a ON a.`id` = l.`from_id`
                   JOIN `content_page` b ON b.`id` = l.`to_id`
                  WHERE (a.`visible` = 1
                         AND (a.`publish_at` IS NULL OR a.`publish_at` <= :now)
                         AND (a.`expire_at`  IS NULL OR a.`expire_at`  >  :now2)
                         AND (a.`visibility` = \'public\' OR (:priv = 1 AND a.`visibility` = \'private\')))
                    AND (b.`visible` = 1
                         AND (b.`publish_at` IS NULL OR b.`publish_at` <= :now3)
                         AND (b.`expire_at`  IS NULL OR b.`expire_at`  >  :now4)
                         AND (b.`visibility` = \'public\' OR (:priv2 = 1 AND b.`visibility` = \'private\')))'
            );
            $estmt->bindValue(':now', $now, PDO::PARAM_INT);
            $estmt->bindValue(':now2', $now, PDO::PARAM_INT);
            $estmt->bindValue(':now3', $now, PDO::PARAM_INT);
            $estmt->bindValue(':now4', $now, PDO::PARAM_INT);
            $estmt->bindValue(':priv', $includePrivate ? 1 : 0, PDO::PARAM_INT);
            $estmt->bindValue(':priv2', $includePrivate ? 1 : 0, PDO::PARAM_INT);
            $estmt->execute();
            /** @var array<string,mixed> $r */
            foreach ($estmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $edges[] = ['from' => $this->i($r['f'] ?? null), 'to' => $this->i($r['t'] ?? null)];
            }

            return Result::ok(['nodes' => $nodes, 'edges' => $edges]);
        } catch (PDOException $e) {
            return Result::err(['nodes' => [], 'edges' => []], $this->diag($e));
        }
    }

    // -------------------------------------------------------------------------

    /** @param list<string> $targets */
    private function rebuildOutboundLinks(int $fromId, string $ownSlug, array $targets): void
    {
        $del = $this->pdo->prepare('DELETE FROM `content_link` WHERE `from_id` = :i');
        $del->bindValue(':i', $fromId, PDO::PARAM_INT);
        $del->execute();

        $ins = $this->pdo->prepare(
            'INSERT INTO `content_link` (`from_id`, `to_slug`, `to_id`)
             VALUES (:f, :s, (SELECT `id` FROM `content_page` WHERE `slug` = :s2))'
        );
        foreach ($targets as $slug) {
            if ($slug === $ownSlug) {
                continue; // ignore self-links in the graph/backlinks
            }
            $ins->bindValue(':f', $fromId, PDO::PARAM_INT);
            $ins->bindValue(':s', $slug);
            $ins->bindValue(':s2', $slug);
            $ins->execute();
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{slug:string,title:string}>
     */
    private function slugTitleRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['slug' => $this->s($r['slug'] ?? null), 'title' => $this->s($r['title'] ?? null)];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:int,slug:string,title:string,visible:bool,visibility:string,publish_at:?int,expire_at:?int,updated_at:string}
     */
    private function listRow(array $r): array
    {
        return [
            'id'         => $this->i($r['id'] ?? null),
            'slug'       => $this->s($r['slug'] ?? null),
            'title'      => $this->s($r['title'] ?? null),
            'visible'    => (bool) ($r['visible'] ?? 0),
            'visibility' => $this->vis($r['visibility'] ?? null),
            'publish_at' => $this->ni($r['publish_at'] ?? null),
            'expire_at'  => $this->ni($r['expire_at'] ?? null),
            'updated_at' => $this->s($r['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:int,slug:string,title:string,body:string,visible:bool,visibility:string,publish_at:?int,expire_at:?int,updated_at:string}
     */
    private function row(array $r): array
    {
        return [
            'id'         => $this->i($r['id'] ?? null),
            'slug'       => $this->s($r['slug'] ?? null),
            'title'      => $this->s($r['title'] ?? null),
            'body'       => $this->s($r['body'] ?? null),
            'visible'    => (bool) ($r['visible'] ?? 0),
            'visibility' => $this->vis($r['visibility'] ?? null),
            'publish_at' => $this->ni($r['publish_at'] ?? null),
            'expire_at'  => $this->ni($r['expire_at'] ?? null),
            'updated_at' => $this->s($r['updated_at'] ?? null),
        ];
    }

    private function diag(PDOException $e): Diagnostics
    {
        return Diagnostics::of(new ContentDbDiagnostic('astrx.content/db_error', DiagnosticLevel::ERROR, $e->getMessage()));
    }

    private function s(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private function i(mixed $v): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }

    /** Nullable int (publish_at / expire_at): NULL stays NULL. */
    private function ni(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : null);
    }

    private function vis(mixed $v): string
    {
        $s = is_scalar($v) ? (string) $v : 'public';
        return in_array($s, ['public', 'unlisted', 'private'], true) ? $s : 'public';
    }
}
