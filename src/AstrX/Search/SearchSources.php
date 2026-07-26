<?php
declare(strict_types=1);

namespace AstrX\Search;

use AstrX\I18n\Translator;
use AstrX\Routing\UrlGenerator;
use PDO;

/**
 * Shared content-source logic for site-wide search.
 *
 * This is the single source of truth for the four searchable sources — news,
 * static pages, comments and imageboard posts — covering (a) their visibility
 * rules, (b) how each hit's site-relative URL is built and (c) the plain-text
 * excerpt. It is consumed by BOTH:
 *
 *   - SearchIndexer   → crawl*() gathers EVERY visible row (keyset-paginated,
 *                       memory-bounded) to populate the FULLTEXT index.
 *   - SiteSearchService → live*() runs the classic bound-LIKE queries as a
 *                       fallback for content created since the last crawl.
 *
 * Keeping both paths behind one class guarantees the crawler and the live
 * fallback can never drift apart on visibility or URL shape. Chat is
 * intentionally excluded — it is ephemeral/private.
 *
 * Every text match is a bound LIKE substring (`%term%`, ALWAYS bound, LIKE
 * metacharacters escaped under `ESCAPE '\'`) so there is no injection surface.
 * Native prepares (`emulate_prepares=false`) return integer columns as ints;
 * mixed→scalar casts go through s()/i() to stay PHPStan-level-10 clean.
 */
final class SearchSources
{
    public const string TYPE_NEWS     = 'news';
    public const string TYPE_PAGES    = 'pages';
    public const string TYPE_COMMENTS = 'comments';
    public const string TYPE_BOARD    = 'board';

    /** @var list<string> The doc_type values, one per source. */
    public const array TYPES = [self::TYPE_NEWS, self::TYPE_PAGES, self::TYPE_COMMENTS, self::TYPE_BOARD];

    /** Plain-text excerpt width (characters) and trailing ellipsis marker. */
    private const int    EXCERPT_WIDTH  = 200;
    private const string EXCERPT_MARKER = '…';

    public function __construct(
        private readonly PDO          $pdo,
        private readonly UrlGenerator $urlGen,
        private readonly Translator   $t,
    ) {}

    // =========================================================================
    // Crawl API — gather ALL visible rows for indexing (keyset-paginated).
    //
    // Each crawl*() fetches up to $limit rows with primary key > $afterId in
    // ascending id order, so the caller loops (feeding back the last id) until
    // an empty batch is returned. This keeps memory bounded regardless of how
    // much content exists.
    // =========================================================================

    /**
     * @return list<array{doc_type:string,ref_id:int,title:string,body:string,url:string,doc_time:int}>
     */
    public function crawlNews(int $afterId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, title, content, UNIX_TIMESTAMP(created_at) AS ts
               FROM news
              WHERE hidden = 0 AND id > :after
              ORDER BY id ASC
              LIMIT :lim"
        );
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':lim',   $limit,   PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $base = $this->newsBase();
        $out  = [];
        foreach ($rows as $row) {
            $id    = $this->i($row['id'] ?? null);
            $out[] = [
                'doc_type' => self::TYPE_NEWS,
                'ref_id'   => $id,
                'title'    => $this->s($row['title'] ?? null),
                'body'     => $this->s($row['content'] ?? null),
                'url'      => $base . '#news_' . $id,
                'doc_time' => $this->i($row['ts'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * @return list<array{doc_type:string,ref_id:int,title:string,body:string,url:string,doc_time:int}>
     */
    public function crawlPages(int $afterId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.url_id, p.i18n, pm.title, pm.description
               FROM page p
               JOIN page_meta pm ON pm.page_id = p.id
              WHERE p.hidden = 0
                AND p.file_name NOT LIKE 'admin%'
                AND p.id > :after
              ORDER BY p.id ASC
              LIMIT :lim"
        );
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':lim',   $limit,   PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $resolved = $this->resolvePageUrlId($row);
            $title    = $this->s($row['title'] ?? null);
            $out[]    = [
                'doc_type' => self::TYPE_PAGES,
                'ref_id'   => $this->i($row['id'] ?? null),
                'title'    => $title !== '' ? $title : $resolved,
                'body'     => $this->s($row['description'] ?? null),
                'url'      => $this->urlGen->toPage($resolved),
                'doc_time' => 0,
            ];
        }
        return $out;
    }

    /**
     * @return list<array{doc_type:string,ref_id:int,title:string,body:string,url:string,doc_time:int}>
     */
    public function crawlComments(int $afterId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.content, pg.url_id, pg.i18n, pm.title AS page_title,
                    UNIX_TIMESTAMP(c.created_at) AS ts
               FROM comment c
               JOIN page pg ON pg.id = c.page_id
               LEFT JOIN page_meta pm ON pm.page_id = pg.id
              WHERE c.hidden = 0 AND pg.hidden = 0
                AND c.id > :after
              ORDER BY c.id ASC
              LIMIT :lim"
        );
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':lim',   $limit,   PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $resolved  = $this->resolvePageUrlId($row);
            $pageTitle = $this->s($row['page_title'] ?? null);
            $id        = $this->i($row['id'] ?? null);
            $out[]     = [
                'doc_type' => self::TYPE_COMMENTS,
                'ref_id'   => $id,
                'title'    => $pageTitle !== '' ? $pageTitle : $resolved,
                'body'     => $this->s($row['content'] ?? null),
                'url'      => $this->urlGen->toPage($resolved) . '#comment-' . $id,
                'doc_time' => $this->i($row['ts'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * @return list<array{doc_type:string,ref_id:int,title:string,body:string,url:string,doc_time:int}>
     */
    public function crawlBoard(int $afterId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.thread_id, p.`no`, p.subject, p.body_raw, b.slug,
                    UNIX_TIMESTAMP(p.created_at) AS ts
               FROM board_post p
               JOIN board b ON b.id = p.board_id AND b.active = 1
              WHERE p.banned = 0
                AND p.id > :after
              ORDER BY p.id ASC
              LIMIT :lim"
        );
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':lim',   $limit,   PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $boardBase = $this->boardBase();
        $out       = [];
        foreach ($rows as $row) {
            $slug    = $this->s($row['slug'] ?? null);
            $subject = $this->s($row['subject'] ?? null);
            $out[]   = [
                'doc_type' => self::TYPE_BOARD,
                'ref_id'   => $this->i($row['id'] ?? null),
                'title'    => $subject !== '' ? $subject : ('/' . $slug . '/'),
                // body_raw (not the rendered HTML) is indexed so FULLTEXT
                // matching lines up with the live LIKE fallback, which also
                // matches on body_raw.
                'body'     => $this->s($row['body_raw'] ?? null),
                'url'      => $boardBase . '/' . rawurlencode($slug)
                            . '/thread/' . $this->i($row['thread_id'] ?? null)
                            . '#p' . $this->i($row['no'] ?? null),
                'doc_time' => $this->i($row['ts'] ?? null),
            ];
        }
        return $out;
    }

    // =========================================================================
    // Live API — classic bound-LIKE queries used as the fallback for content
    // newer than the last crawl. Rows with UNIX time strictly greater than
    // $minTime are returned; pass $minTime = 0 to include everything (used when
    // the index is empty or unavailable, degrading to the pre-index behaviour).
    //
    // Pages carry no timestamp, so livePages() is only meaningful with a zero
    // cutoff; SiteSearchService gates it on an empty index accordingly.
    // =========================================================================

    /** @return list<array{type:string,ref_id:int,title:string,excerpt:string,url:string,time:int}> */
    public function liveNews(string $like, int $limit, int $minTime): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, title, content, UNIX_TIMESTAMP(created_at) AS ts
               FROM news
              WHERE hidden = 0
                AND UNIX_TIMESTAMP(created_at) > :mt
                AND (title LIKE :q ESCAPE '\\\\' OR content LIKE :q2 ESCAPE '\\\\')
              ORDER BY created_at DESC
              LIMIT :lim"
        );
        $stmt->bindValue(':mt',  $minTime, PDO::PARAM_INT);
        $stmt->bindValue(':q',   $like);
        $stmt->bindValue(':q2',  $like);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $base = $this->newsBase();
        $out  = [];
        foreach ($rows as $row) {
            $id    = $this->i($row['id'] ?? null);
            $out[] = [
                'type'    => self::TYPE_NEWS,
                'ref_id'  => $id,
                'title'   => $this->s($row['title'] ?? null),
                'excerpt' => $this->excerpt($this->s($row['content'] ?? null)),
                'url'     => $base . '#news_' . $id,
                'time'    => $this->i($row['ts'] ?? null),
            ];
        }
        return $out;
    }

    /** @return list<array{type:string,ref_id:int,title:string,excerpt:string,url:string,time:int}> */
    public function livePages(string $like, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.url_id, p.i18n, pm.title, pm.description
               FROM page p
               JOIN page_meta pm ON pm.page_id = p.id
              WHERE p.hidden = 0
                AND p.file_name NOT LIKE 'admin%'
                AND (pm.title LIKE :q ESCAPE '\\\\' OR pm.description LIKE :q2 ESCAPE '\\\\')
              ORDER BY pm.title ASC
              LIMIT :lim"
        );
        $stmt->bindValue(':q',   $like);
        $stmt->bindValue(':q2',  $like);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $resolved = $this->resolvePageUrlId($row);
            $title    = $this->s($row['title'] ?? null);
            $out[]    = [
                'type'    => self::TYPE_PAGES,
                'ref_id'  => $this->i($row['id'] ?? null),
                'title'   => $title !== '' ? $title : $resolved,
                'excerpt' => $this->excerpt($this->s($row['description'] ?? null)),
                'url'     => $this->urlGen->toPage($resolved),
                'time'    => 0,
            ];
        }
        return $out;
    }

    /** @return list<array{type:string,ref_id:int,title:string,excerpt:string,url:string,time:int}> */
    public function liveComments(string $like, int $limit, int $minTime): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.content, pg.url_id, pg.i18n, pm.title AS page_title,
                    UNIX_TIMESTAMP(c.created_at) AS ts
               FROM comment c
               JOIN page pg ON pg.id = c.page_id
               LEFT JOIN page_meta pm ON pm.page_id = pg.id
              WHERE c.hidden = 0 AND pg.hidden = 0
                AND UNIX_TIMESTAMP(c.created_at) > :mt
                AND c.content LIKE :q ESCAPE '\\\\'
              ORDER BY c.created_at DESC
              LIMIT :lim"
        );
        $stmt->bindValue(':mt',  $minTime, PDO::PARAM_INT);
        $stmt->bindValue(':q',   $like);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $resolved  = $this->resolvePageUrlId($row);
            $pageTitle = $this->s($row['page_title'] ?? null);
            $id        = $this->i($row['id'] ?? null);
            $out[]     = [
                'type'    => self::TYPE_COMMENTS,
                'ref_id'  => $id,
                'title'   => $pageTitle !== '' ? $pageTitle : $resolved,
                'excerpt' => $this->excerpt($this->s($row['content'] ?? null)),
                'url'     => $this->urlGen->toPage($resolved) . '#comment-' . $id,
                'time'    => $this->i($row['ts'] ?? null),
            ];
        }
        return $out;
    }

    /** @return list<array{type:string,ref_id:int,title:string,excerpt:string,url:string,time:int}> */
    public function liveBoard(string $like, int $limit, int $minTime): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.thread_id, p.`no`, p.subject, p.body_html, b.slug,
                    UNIX_TIMESTAMP(p.created_at) AS ts
               FROM board_post p
               JOIN board b ON b.id = p.board_id AND b.active = 1
              WHERE p.banned = 0
                AND UNIX_TIMESTAMP(p.created_at) > :mt
                AND (p.body_raw LIKE :q ESCAPE '\\\\' OR p.subject LIKE :q2 ESCAPE '\\\\')
              ORDER BY p.id DESC
              LIMIT :lim"
        );
        $stmt->bindValue(':mt',  $minTime, PDO::PARAM_INT);
        $stmt->bindValue(':q',   $like);
        $stmt->bindValue(':q2',  $like);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $boardBase = $this->boardBase();
        $out       = [];
        foreach ($rows as $row) {
            $slug    = $this->s($row['slug'] ?? null);
            $subject = $this->s($row['subject'] ?? null);
            $out[]   = [
                'type'    => self::TYPE_BOARD,
                'ref_id'  => $this->i($row['id'] ?? null),
                'title'   => $subject !== '' ? $subject : ('/' . $slug . '/'),
                'excerpt' => $this->excerpt($this->s($row['body_html'] ?? null)),
                'url'     => $boardBase . '/' . rawurlencode($slug)
                           . '/thread/' . $this->i($row['thread_id'] ?? null)
                           . '#p' . $this->i($row['no'] ?? null),
                'time'    => $this->i($row['ts'] ?? null),
            ];
        }
        return $out;
    }

    // =========================================================================
    // Shared helpers — URL bases, page URL resolution, excerpts, casts.
    // =========================================================================

    /** Build a plain-text, width-limited excerpt from possibly-HTML text. */
    public function excerpt(string $text): string
    {
        return mb_strimwidth(strip_tags($text), 0, self::EXCERPT_WIDTH, self::EXCERPT_MARKER);
    }

    /** URL of the main page (news lives there, anchored per item). */
    private function newsBase(): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_MAIN', fallback: 'WORDING_MAIN'));
    }

    /** URL of the board root (board hits are relative to it). */
    private function boardBase(): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_BOARD', fallback: 'WORDING_BOARD'));
    }

    /**
     * Resolve a page row's translated url_id (mirrors SiteSearchService: i18n
     * pages are translated for the current locale, others used verbatim).
     *
     * @param array<string,mixed> $row
     */
    private function resolvePageUrlId(array $row): string
    {
        $urlId = $this->s($row['url_id'] ?? null);
        return ($this->i($row['i18n'] ?? null) === 1)
            ? $this->t->t($urlId, fallback: $urlId)
            : $urlId;
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
