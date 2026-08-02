<?php
declare(strict_types=1);

namespace AstrX\Search;

use AstrX\Module\ModuleRegistry;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\Search\Diagnostic\SearchDbDiagnostic;
use PDO;
use PDOException;

/**
 * Site-wide search across the CMS's public content: news items, static pages,
 * comments and imageboard posts. Chat is intentionally NOT indexed — it is
 * ephemeral/private.
 *
 * Two layers, merged:
 *
 *   1. INDEX — a MySQL/MariaDB FULLTEXT table (`search_index`) populated
 *      on demand by {@see SearchIndexer}. Matched with
 *      `MATCH(title,body) AGAINST(:q IN BOOLEAN MODE)`, ranked by relevance
 *      and optionally narrowed by doc_type.
 *
 *   2. LIVE FALLBACK — the classic bound-LIKE queries (via {@see SearchSources})
 *      run only for source rows created at/after the crawl start (the oldest
 *      indexed_at), so content created since — or during — the last crawl is
 *      found immediately. When the index
 *      is empty or unavailable (e.g. not yet migrated) the fallback widens to a
 *      full live search, so search degrades gracefully to its pre-index
 *      behaviour and never hard-fails on a missing table.
 *
 * Results from both layers are de-duplicated by (doc_type, ref_id), merged
 * newest-first and capped to the requested limit. Each hit carries a
 * ready-to-use, site-relative URL (Tor-safe: no external requests) and a
 * plain-text excerpt.
 *
 * All text matches are bound (never interpolated) and LIKE metacharacters are
 * escaped, so there is no SQL-injection surface. The FULLTEXT query is built
 * from whitespace-split terms with boolean operators stripped, so raw user
 * input cannot form an invalid boolean expression. Native prepares
 * (`emulate_prepares=false`) mean integer columns come back as ints; casts go
 * through s()/i() to stay PHPStan-level-10 clean.
 */
final class SiteSearchService
{
    /** The accepted `$type` filter values. 'all' includes every source. */
    private const array TYPES = ['all', 'news', 'pages', 'comments', 'board'];

    /** Hard ceiling on the per-source and merged result count. */
    private const int MAX_LIMIT = 200;

    /**
     * Content doc_type → the optional module that owns it. When that module is
     * disabled, its content must NOT be served through search (the interactive
     * pages 404 via ModulePageGuard, but search is a sibling aggregator that would
     * otherwise leak a disabled imageboard's/content's posts to anonymous users).
     */
    private const array TYPE_MODULE = ['board' => 'imageboard', 'pages' => 'content'];

    public function __construct(
        private readonly PDO            $pdo,
        private readonly SearchSources  $sources,
        private readonly ModuleRegistry $registry,
    ) {}

    /**
     * Run a search over the selected content type(s).
     *
     * @param string $type One of {'all','news','pages','comments','board'};
     *                     anything else is treated as 'all'.
     * @return Result<list<array{type:string,title:string,excerpt:string,url:string,time:int}>>
     */
    public function search(string $query, string $type, int $limit): Result
    {
        $query = trim($query);
        if ($query === '') {
            return Result::ok([]);
        }

        if (!in_array($type, self::TYPES, true)) {
            $type = 'all';
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // Escape the backslash first, then the LIKE wildcards, so a user
        // searching for "50%" or "a_b" matches those literally under ESCAPE '\'.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $like    = '%' . $escaped . '%';

        try {
            // Layer 1: the FULLTEXT index (may be empty/unavailable).
            [$indexHits, $indexAvailable] = $this->fromIndex($query, $type, $limit);

            // R11 / R10-deferred (MED): the index stores title/body/url as they
            // were at crawl time, so content HIDDEN/DELETED/BANNED/DEACTIVATED
            // since indexing would keep surfacing until the next crawl. Re-check
            // each index hit against live visibility and drop the suppressed ones.
            $indexHits = $this->sources->revalidate($indexHits);

            // Layer 2: live fallback. When the index is unavailable or empty we
            // run a FULL live search (cutoff 0, pages included); otherwise only
            // rows created at/after the crawl start (oldest indexed_at) are
            // fetched, so content created mid-crawl is not hidden (R4-24).
            $cutoff     = $indexAvailable ? $this->indexCutoff() : 0;
            $indexEmpty = $cutoff === 0;
            $liveHits   = $this->fromLive($like, $type, $limit, $cutoff, includePages: $indexEmpty);
        } catch (PDOException $e) {
            return Result::err([], $this->pdoDiagnostic($e));
        }

        // Index hits first so a boundary duplicate keeps its ranked index row.
        $merged = $this->dedupe(array_merge($indexHits, $liveHits));

        // Drop content whose owning module is disabled — the single choke point
        // that covers BOTH the live query and the (possibly stale) FULLTEXT index,
        // so disabling e.g. the imageboard immediately stops search surfacing its
        // posts even though the crawler may still hold indexed board rows.
        $disabled = $this->registry->disabledModules();
        if ($disabled !== []) {
            $merged = array_values(array_filter($merged, static function (array $hit) use ($disabled): bool {
                $module = self::TYPE_MODULE[$hit['type']] ?? null;
                return $module === null || !in_array($module, $disabled, true);
            }));
        }

        // Merge newest-first (pages carry time 0 and sink to the bottom), cap.
        usort($merged, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
        if (count($merged) > $limit) {
            $merged = array_slice($merged, 0, $limit);
        }

        return Result::ok($this->toPublic($merged));
    }

    // -------------------------------------------------------------------------
    // Layer 1 — FULLTEXT index
    // -------------------------------------------------------------------------

    /**
     * Query the FULLTEXT index. Returns [hits, available]; available is false
     * when the table is missing/unreadable so the caller can widen the live
     * fallback to a full search instead of hard-failing.
     *
     * @return array{0:list<array{type:string,ref_id:int,title:string,excerpt:string,url:string,time:int}>,1:bool}
     */
    private function fromIndex(string $query, string $type, int $limit): array
    {
        $boolean = $this->booleanQuery($query);
        if ($boolean === '') {
            // Nothing usable for FULLTEXT (e.g. only operator chars); the live
            // fallback still handles the raw query. Treat the index as available
            // so the fallback stays bounded rather than running a full search.
            return [[], true];
        }

        $sql = "SELECT `doc_type`, `ref_id`, `title`, `body`, `url`, `doc_time`,
                       MATCH(`title`,`body`) AGAINST (:q  IN BOOLEAN MODE) AS score
                  FROM `search_index`
                 WHERE MATCH(`title`,`body`) AGAINST (:q2 IN BOOLEAN MODE)";
        if ($type !== 'all') {
            $sql .= " AND `doc_type` = :dt";
        }
        $sql .= " ORDER BY score DESC, `doc_time` DESC LIMIT :lim";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':q',  $boolean);
            $stmt->bindValue(':q2', $boolean);
            if ($type !== 'all') {
                $stmt->bindValue(':dt', $type);
            }
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException) {
            // Table absent / not yet migrated → degrade to full live search.
            return [[], false];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'type'    => $this->s($row['doc_type'] ?? null),
                'ref_id'  => $this->i($row['ref_id'] ?? null),
                'title'   => $this->s($row['title'] ?? null),
                'excerpt' => $this->sources->excerpt($this->s($row['body'] ?? null)),
                'url'     => $this->s($row['url'] ?? null),
                'time'    => $this->i($row['doc_time'] ?? null),
            ];
        }
        return [$out, true];
    }

    /**
     * Crawl-start cutoff as a unix timestamp, or 0 when the index is empty.
     *
     * R4-24: this is the OLDEST indexed_at, not the newest. A crawl stamps each
     * row's indexed_at as it upserts, so MAX(indexed_at) is the crawl END —
     * using it hides content created mid-crawl (after its source was already
     * crawled) until the next full crawl, because such rows are neither indexed
     * nor newer than the end cutoff. MIN(indexed_at) approximates the crawl
     * START (and is older still for a partial index), so the live fallback
     * covers everything created at/after it; boundary duplicates with the index
     * are removed by dedupe().
     */
    private function indexCutoff(): int
    {
        $stmt = $this->pdo->query('SELECT UNIX_TIMESTAMP(MIN(`indexed_at`)) FROM `search_index`');
        if ($stmt === false) {
            return 0;
        }
        return $this->i($stmt->fetchColumn());
    }

    /**
     * Build a boolean-mode expression from the raw query: each whitespace term
     * becomes a required prefix match (`+term*`), with boolean operator
     * characters stripped so user input can never form an invalid expression.
     * Returns '' when nothing usable remains.
     */
    private function booleanQuery(string $query): string
    {
        $terms = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        if ($terms === false) {
            return '';
        }

        $parts = [];
        foreach ($terms as $term) {
            $clean = str_replace(
                ['+', '-', '<', '>', '(', ')', '~', '*', '"', '@'],
                '',
                $term,
            );
            if ($clean !== '') {
                $parts[] = '+' . $clean . '*';
            }
        }
        return implode(' ', $parts);
    }

    // -------------------------------------------------------------------------
    // Layer 2 — live fallback
    // -------------------------------------------------------------------------

    /**
     * Run the live LIKE queries for the selected type(s), restricted to rows
     * newer than $cutoff. Pages carry no timestamp, so they are only queried
     * when $includePages is true (an empty/unavailable index → full search).
     *
     * @return list<array{type:string,ref_id:int,title:string,excerpt:string,url:string,time:int}>
     */
    private function fromLive(string $like, string $type, int $limit, int $cutoff, bool $includePages): array
    {
        $hits = [];
        if ($type === 'all' || $type === 'news') {
            $hits = array_merge($hits, $this->sources->liveNews($like, $limit, $cutoff));
        }
        if (($type === 'all' || $type === 'pages') && $includePages) {
            $hits = array_merge($hits, $this->sources->livePages($like, $limit));
        }
        if ($type === 'all' || $type === 'comments') {
            $hits = array_merge($hits, $this->sources->liveComments($like, $limit, $cutoff));
        }
        if ($type === 'all' || $type === 'board') {
            $hits = array_merge($hits, $this->sources->liveBoard($like, $limit, $cutoff));
        }
        return $hits;
    }

    // -------------------------------------------------------------------------
    // Merge helpers
    // -------------------------------------------------------------------------

    /**
     * De-duplicate by (doc_type, ref_id), keeping the first occurrence.
     *
     * @param list<array{type:string,ref_id:int,title:string,excerpt:string,url:string,time:int}> $hits
     * @return list<array{type:string,ref_id:int,title:string,excerpt:string,url:string,time:int}>
     */
    private function dedupe(array $hits): array
    {
        $seen = [];
        $out  = [];
        foreach ($hits as $hit) {
            $key = $hit['type'] . ':' . $hit['ref_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[]      = $hit;
        }
        return $out;
    }

    /**
     * Project internal hits (which carry ref_id for de-duplication) onto the
     * public result shape.
     *
     * @param list<array{type:string,ref_id:int,title:string,excerpt:string,url:string,time:int}> $hits
     * @return list<array{type:string,title:string,excerpt:string,url:string,time:int}>
     */
    private function toPublic(array $hits): array
    {
        $out = [];
        foreach ($hits as $hit) {
            $out[] = [
                'type'    => $hit['type'],
                'title'   => $hit['title'],
                'excerpt' => $hit['excerpt'],
                'url'     => $hit['url'],
                'time'    => $hit['time'],
            ];
        }
        return $out;
    }

    private function pdoDiagnostic(PDOException $e): Diagnostics
    {
        return Diagnostics::of(new SearchDbDiagnostic(
            'astrx.search/db_error',
            DiagnosticLevel::ERROR,
            $e->getMessage(),
        ));
    }

    /** Cast mixed→string safely for PHPStan level 10. */
    private function s(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    /** Cast mixed→int safely for PHPStan level 10. */
    private function i(mixed $v): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }
}
