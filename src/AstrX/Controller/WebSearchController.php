<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Api\ContextScope;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;
use AstrX\WebSearch\WebSearchClient;
use function AstrX\Support\langDir;

/**
 * Clear-web search — a no-JavaScript GET form that bridges to the standalone,
 * localhost-only astrx-websearch engine over its JSON API. This is a SEPARATE
 * page from the internal site search (SiteSearchController) and the onion search
 * (OnionSearchController); the three are intentionally NOT merged.
 *
 * The page is seeded with file_name 'web_search' so the reflection router
 * resolves it to THIS class (str_replace('_','',ucwords('web_search','_')) .
 * 'Controller'); its URL slug is WORDING_WEBSEARCH ('websearch'). All HTTP,
 * JSON parsing and sanitisation happen in WebSearchClient; this controller only
 * translates, shapes the view model and gates access. Public: gated by
 * NEWS_VIEW (granted to guests), exactly like the internal search.
 *
 * Every result field arrives from WebSearchClient already stripped of markup and
 * is rendered through plain `{{ }}` (escaped) in web_search.html — never `{{&}}`
 * — so crawled, untrusted content has zero XSS surface on the AstrX side.
 */
final class WebSearchController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly WebSearchClient        $client,
    ) {
        parent::__construct($collector);
    }

    /** Vertical tabs offered on the page; the first is the default. */
    private const array VERTICALS = ['web', 'news', 'files'];

    /** Order-by choices (mapped to the engine's relevance|fresh sort). */
    private const array ORDERS = ['relevance', 'newest'];

    /** Results-per-page: a free integer input, clamped to this range. */
    private const int PER_PAGE_DEFAULT = 10;
    private const int PER_PAGE_MIN = 1;
    private const int PER_PAGE_MAX = 100;

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'WebSearch');

        if ($this->gate->cannot(Permission::NEWS_VIEW)) {
            http_response_code(404);
            exit;
        }

        $q    = self::queryStr($this->request, 'q');
        $page = max(1, self::queryInt($this->request, 'page', 1));

        // Whitelisted controls — anything else falls back to the default.
        $type = self::queryStr($this->request, 'type');
        if (!in_array($type, self::VERTICALS, true)) {
            $type = 'web';
        }
        $order = self::queryStr($this->request, 'order');
        if (!in_array($order, self::ORDERS, true)) {
            $order = 'relevance';
        }
        // Results per page: a free number input, clamped to a sane range.
        $perPage = self::queryInt($this->request, 'per_page', self::PER_PAGE_DEFAULT);
        $perPage = max(self::PER_PAGE_MIN, min(self::PER_PAGE_MAX, $perPage));
        // Map UI tokens → engine params (type=web is the engine default; order
        // "newest" → sort=fresh).
        $engineType = $type === 'web' ? '' : $type;
        $engineSort = $order === 'newest' ? 'fresh' : 'relevance';

        $slug       = $this->t->t('WORDING_WEBSEARCH');
        $formAction = $this->urlGen->toPage($slug);
        // Carried on every tab / pager / form link so the vertical, order and
        // page size survive navigation.
        $state = ['type' => $type, 'order' => $order, 'per_page' => $perPage];

        $searched    = $q !== '';
        $unavailable = false;
        $results     = [];
        $total       = 0;
        $hasPrev     = false;
        $hasNext     = false;
        $prevUrl     = '';
        $nextUrl     = '';

        if ($searched) {
            $resp = $this->client->search($q, $page, $engineType, $engineSort, $perPage);
            if ($resp['ok']) {
                $total    = $resp['total'];
                $page     = $resp['page'];
                $pageSize = $resp['page_size'];
                foreach ($resp['results'] as $r) {
                    $results[] = [
                        // Fall back to the URL as the link text when the crawl
                        // captured no <title>. Both are already sanitised.
                        'title'   => $r['title'] !== '' ? $r['title'] : $r['url'],
                        'url'     => $r['url'],
                        'href'    => $r['href'],
                        'host'    => $r['host'],
                        'snippet' => $r['snippet'],
                    ];
                }
                $last = max(1, intdiv($total + $pageSize - 1, $pageSize));
                if ($page > 1) {
                    $hasPrev = true;
                    $prevUrl = $this->urlGen->toPage($slug, ['q' => $q, 'page' => $page - 1] + $state);
                }
                if ($page < $last) {
                    $hasNext = true;
                    $nextUrl = $this->urlGen->toPage($slug, ['q' => $q, 'page' => $page + 1] + $state);
                }
            } else {
                $unavailable = true;
            }
        }

        // Data values are SHARED: safe to render in the HTML AND to expose to an
        // API caller should the operator later flip page.api_enabled on. The
        // key is 'web_results', NOT 'results' — DefaultTemplateContext reserves
        // 'results' for the diagnostic message list and clobbers it in finalise().
        $this->ctx->set('form_action', $formAction);
        $this->ctx->setShared('query',               $q);
        $this->ctx->setShared('searched',            $searched);
        $this->ctx->setShared('backend_unavailable', $unavailable);
        $this->ctx->setShared('web_results',         $results);
        $this->ctx->setShared('has_results',         $results !== []);
        $this->ctx->setShared('result_count',        $this->t->t('websearch.result_count', ['count' => $total]));
        $this->ctx->setShared('page_num',            $page);

        // Controls: current values (hidden fields keep them on query submit),
        // the vertical tabs, and the per-page + order <select> option lists.
        $this->ctx->set('cur_type',         $type);
        $this->ctx->set('cur_order',        $order);
        $this->ctx->set('cur_per_page',     $perPage);
        $this->ctx->set('verticals',        $this->buildVerticals($slug, $q, $type, $order, $perPage));
        $this->ctx->set('order_options',    $this->buildOrderOptions($order));

        $this->ctx->set('has_prev', $hasPrev);
        $this->ctx->set('prev_url', $prevUrl);
        $this->ctx->set('has_next', $hasNext);
        $this->ctx->set('next_url', $nextUrl);

        $this->setLabels();

        return $this->ok();
    }

    /**
     * Vertical tabs (Web / News / Files). Each link preserves the query, order
     * and page size; the engine treats an absent/"web" type as the default.
     *
     * @return list<array{key:string,label:string,url:string,active:bool}>
     */
    private function buildVerticals(string $slug, string $q, string $type, string $order, int $perPage): array
    {
        $out = [];
        foreach (self::VERTICALS as $v) {
            $params = ['type' => $v, 'order' => $order, 'per_page' => $perPage];
            if ($q !== '') {
                $params['q'] = $q;
            }
            $out[] = [
                'key'    => $v,
                'label'  => $this->t->t('websearch.vertical.' . $v),
                'url'    => $this->urlGen->toPage($slug, $params),
                'active' => $v === $type,
            ];
        }
        return $out;
    }

    /** @return list<array{value:string,label:string,selected:bool}> */
    private function buildOrderOptions(string $cur): array
    {
        $out = [];
        foreach (self::ORDERS as $o) {
            $out[] = [
                'value'    => $o,
                'label'    => $this->t->t('websearch.order.' . $o),
                'selected' => $o === $cur,
            ];
        }
        return $out;
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_heading'     => 'websearch.heading',
            'lbl_intro'       => 'websearch.intro',
            'lbl_query'       => 'websearch.query_label',
            'lbl_submit'      => 'websearch.submit',
            'lbl_no_results'  => 'websearch.no_results',
            'lbl_unavailable' => 'websearch.unavailable',
            'lbl_prev'        => 'websearch.prev',
            'lbl_next'        => 'websearch.next',
            'lbl_per_page'    => 'websearch.per_page',
            'lbl_order'       => 'websearch.order_label',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey), ContextScope::SHARED);
        }
    }
}
