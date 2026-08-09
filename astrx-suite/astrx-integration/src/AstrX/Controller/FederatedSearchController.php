<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Api\ContextScope;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\FederatedSearch\FederatedSearchClient;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Search\SiteSearchService;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Unified (federated) search — a no-JavaScript GET page with ONE query box that
 * fans a single query out to four sources, each behind its own no-JS tab:
 *
 *   internal — AstrX's own content, via {@see SiteSearchService} (no network);
 *   web      — the clear-web engine (websearch), via {@see FederatedSearchClient};
 *   onion    — the onion engine (onioncrawler), via the same bridge;
 *   torrent  — the torrent DHT index (torrentds), via the same bridge.
 *
 * "Tabs" are plain `?source=` links (no JavaScript): exactly ONE source is active
 * per request, so the page only ever does the work of the visible tab — one
 * in-process query OR one bounded, size-capped localhost HTTP call. A down HTTP
 * source degrades to a friendly "source unavailable" panel and can never 500 the
 * page (`@`-suppressed transport in the client); the other tabs keep working.
 *
 * The page is seeded with file_name 'federated_search' so the reflection router
 * resolves it to THIS class (str_replace('_','',ucwords('federated_search','_'))
 * . 'Controller'); its URL slug is WORDING_FEDSEARCH ('search-all'). This is a
 * SEPARATE page from the internal site search ('search') and the three dedicated
 * suite search pages; it aggregates them without replacing any. Public: gated by
 * NEWS_VIEW (granted to guests), exactly like the sibling search pages.
 *
 * Every engine field arrives from FederatedSearchClient already stripped of
 * markup, and every internal field is stripped here too; all are rendered through
 * plain `{{ }}` (escaped) in federated_search.html — never `{{&}}` — so crawled /
 * DHT-sourced / user content has zero XSS surface on the AstrX side.
 */
final class FederatedSearchController extends AbstractController
{
    /** The four tab sources, in display order. 'internal' is the default. */
    private const array SOURCES = ['internal', 'web', 'onion', 'torrent'];

    /** Hard cap on internal hits surfaced on the unified page. */
    private const int INTERNAL_LIMIT = 25;

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly FederatedSearchClient  $client,
        private readonly SiteSearchService      $search,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'FederatedSearch');

        if ($this->gate->cannot(Permission::NEWS_VIEW)) {
            http_response_code(404);
            exit;
        }

        $q      = self::queryStr($this->request, 'q');
        $source = self::queryStr($this->request, 'source');
        if (!in_array($source, self::SOURCES, true)) {
            $source = 'internal';
        }

        $slug     = $this->t->t('WORDING_FEDSEARCH');
        $searched = $q !== '';

        // ── Tabs (always shown so every source is reachable) ───────────────────
        $tabs = [];
        foreach (self::SOURCES as $s) {
            $params = ['source' => $s];
            if ($q !== '') {
                $params['q'] = $q;
            }
            $tabs[] = [
                'label'  => $this->t->t('fedsearch.tab.' . $s),
                'url'    => $this->urlGen->toPage($slug, $params),
                'active' => $s === $source,
            ];
        }

        // ── Dispatch to the ONE active source ──────────────────────────────────
        $unavailable    = false;
        $count          = 0;
        $internalRows   = [];
        $linkRows       = [];
        $torrentRows    = [];

        if ($searched) {
            switch ($source) {
                case 'web':
                    $resp = $this->client->searchWeb($q);
                    $unavailable = !$resp['ok'];
                    $linkRows    = $resp['results'];
                    $count       = count($linkRows);
                    break;
                case 'onion':
                    $resp = $this->client->searchOnion($q);
                    $unavailable = !$resp['ok'];
                    $linkRows    = $resp['results'];
                    $count       = count($linkRows);
                    break;
                case 'torrent':
                    $resp        = $this->client->searchTorrent($q);
                    $unavailable = !$resp['ok'];
                    $torrentRows = $resp['results'];
                    $count       = count($torrentRows);
                    break;
                default: // 'internal'
                    $internalRows = $this->internalSearch($q);
                    $count        = count($internalRows);
                    break;
            }
        }

        $hasResults = ($internalRows !== [] || $linkRows !== [] || $torrentRows !== []);

        // ── View model (SHARED: safe for HTML and any future API mirror) ───────
        $this->ctx->set('form_action', $this->urlGen->toPage($slug));
        $this->ctx->setShared('query',               $q);
        $this->ctx->setShared('active_source',       $source);
        $this->ctx->setShared('tabs',                $tabs);
        $this->ctx->setShared('searched',            $searched);
        $this->ctx->setShared('backend_unavailable', $unavailable);
        $this->ctx->setShared('has_results',         $hasResults);
        $this->ctx->setShared('result_count',        $this->t->t('fedsearch.result_count', ['count' => $count]));

        // Exactly one of these render blocks is populated per request.
        $this->ctx->setShared('src_internal',    $source === 'internal');
        $this->ctx->setShared('src_link',        $source === 'web' || $source === 'onion');
        $this->ctx->setShared('src_torrent',     $source === 'torrent');
        $this->ctx->setShared('internal_results', $internalRows);
        $this->ctx->setShared('link_results',     $linkRows);
        $this->ctx->setShared('torrent_results',  $torrentRows);

        $this->setLabels();

        return $this->ok();
    }

    /**
     * Search internal AstrX content via the in-process site-search service. Every
     * surfaced field is tag-stripped here too, so the "internal" tab observes the
     * same no-markup contract as the crawled sources.
     *
     * @return list<array{type_label:string,title:string,excerpt:string,url:string}>
     */
    private function internalSearch(string $q): array
    {
        $r    = $this->search->search($q, 'all', self::INTERNAL_LIMIT)->drainTo($this->collector);
        $rows = $r->isOk() ? $r->unwrap() : [];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'type_label' => $this->t->t('fedsearch.type.' . $row['type']),
                'title'      => self::plain($row['title']),
                'excerpt'    => self::plain($row['excerpt']),
                // Site-relative internal URL from SiteSearchService, reduced to a
                // safe href for parity with the web/onion tabs (a hostile scheme
                // that ever reached the url column collapses to '#').
                'url'        => self::safeInternalHref($row['url']),
            ];
        }
        return $out;
    }

    /** Tag-free plain text for internal fields (belt-and-suspenders before {{ }}). */
    private static function plain(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', strip_tags($s));
        return trim(is_string($collapsed) ? $collapsed : $s);
    }

    /**
     * Reduce an internal search URL to a safe href, mirroring
     * {@see FederatedSearchClient::safeHref} for the crawled tabs but adapted to
     * internal targets. SiteSearchService emits SITE-RELATIVE paths (e.g.
     * /news/slug, index.php?…), so a scheme-less value is kept; a scheme-bearing
     * value is allowed only when the scheme is http(s). A protocol-relative
     * `//host`, a `javascript:` / `data:` (or any other) scheme, or a malformed
     * URL collapses to `#`, so a hostile scheme that somehow reached the url
     * column can never become a clickable XSS.
     */
    private static function safeInternalHref(string $url): string
    {
        // A control char (CR/LF/TAB/…) is stripped by browsers before scheme
        // resolution, so `java<LF>script:` would smuggle a javascript: scheme past
        // the parse below — reject any such URL, plus the empty and the
        // protocol-relative `//host` cases, outright.
        if ($url === '' || str_starts_with($url, '//') || preg_match('/[\x00-\x1f]/', $url) === 1) {
            return '#';
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null) {
            return $url; // no scheme → same-origin relative path
        }
        return (is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true))
            ? $url
            : '#'; // malformed, javascript:, data:, or any non-http(s) scheme
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_heading'     => 'fedsearch.heading',
            'lbl_intro'       => 'fedsearch.intro',
            'lbl_query'       => 'fedsearch.query_label',
            'lbl_submit'      => 'fedsearch.submit',
            'lbl_no_results'  => 'fedsearch.no_results',
            'lbl_unavailable' => 'fedsearch.unavailable',
            'lbl_size'        => 'fedsearch.size',
            'lbl_files'       => 'fedsearch.files',
            'lbl_seen'        => 'fedsearch.seen',
            'lbl_swarm'       => 'fedsearch.swarm',
            'lbl_magnet'      => 'fedsearch.magnet',
            'lbl_torrent'     => 'fedsearch.torrent',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey), ContextScope::SHARED);
        }
    }
}
