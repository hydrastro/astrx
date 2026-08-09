<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Api\ContextScope;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\OnionSearch\OnionSearchClient;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Onion search — a no-JavaScript GET form that bridges to the standalone,
 * localhost-only onioncrawler engine over its JSON API. This is a SEPARATE page
 * from the internal site search (SiteSearchController) and the clear-web search
 * (WebSearchController); the three are intentionally NOT merged.
 *
 * The page is seeded with file_name 'onion_search' so the reflection router
 * resolves it to THIS class (str_replace('_','',ucwords('onion_search','_')) .
 * 'Controller'); its URL slug is WORDING_ONIONSEARCH ('onionsearch'). All HTTP,
 * JSON parsing and sanitisation happen in OnionSearchClient; this controller
 * only translates, shapes the view model and gates access. Public: gated by
 * NEWS_VIEW (granted to guests), exactly like the internal search.
 *
 * Every result field arrives from OnionSearchClient already stripped of markup
 * and is rendered through plain `{{ }}` (escaped) in onion_search.html — never
 * `{{&}}` — so crawled, untrusted .onion content has zero XSS surface on the
 * AstrX side.
 */
final class OnionSearchController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly OnionSearchClient      $client,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'OnionSearch');

        if ($this->gate->cannot(Permission::NEWS_VIEW)) {
            http_response_code(404);
            exit;
        }

        $q    = self::queryStr($this->request, 'q');
        $page = max(1, self::queryInt($this->request, 'page', 1));

        $slug       = $this->t->t('WORDING_ONIONSEARCH');
        $formAction = $this->urlGen->toPage($slug);

        $searched    = $q !== '';
        $unavailable = false;
        $results     = [];
        $total       = 0;
        $hasPrev     = false;
        $hasNext     = false;
        $prevUrl     = '';
        $nextUrl     = '';

        if ($searched) {
            $resp = $this->client->search($q, $page);
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
                    $prevUrl = $this->urlGen->toPage($slug, ['q' => $q, 'page' => $page - 1]);
                }
                if ($page < $last) {
                    $hasNext = true;
                    $nextUrl = $this->urlGen->toPage($slug, ['q' => $q, 'page' => $page + 1]);
                }
            } else {
                $unavailable = true;
            }
        }

        // Data values are SHARED: safe to render in the HTML AND to expose to an
        // API caller should the operator later flip page.api_enabled on. The
        // key is 'onion_results', NOT 'results' — DefaultTemplateContext reserves
        // 'results' for the diagnostic message list and clobbers it in finalise().
        $this->ctx->set('form_action', $formAction);
        $this->ctx->setShared('query',               $q);
        $this->ctx->setShared('searched',            $searched);
        $this->ctx->setShared('backend_unavailable', $unavailable);
        $this->ctx->setShared('onion_results',       $results);
        $this->ctx->setShared('has_results',         $results !== []);
        $this->ctx->setShared('result_count',        $this->t->t('onionsearch.result_count', ['count' => $total]));
        $this->ctx->setShared('page_num',            $page);

        $this->ctx->set('has_prev', $hasPrev);
        $this->ctx->set('prev_url', $prevUrl);
        $this->ctx->set('has_next', $hasNext);
        $this->ctx->set('next_url', $nextUrl);

        $this->setLabels();

        return $this->ok();
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_heading'     => 'onionsearch.heading',
            'lbl_intro'       => 'onionsearch.intro',
            'lbl_query'       => 'onionsearch.query_label',
            'lbl_submit'      => 'onionsearch.submit',
            'lbl_no_results'  => 'onionsearch.no_results',
            'lbl_unavailable' => 'onionsearch.unavailable',
            'lbl_prev'        => 'onionsearch.prev',
            'lbl_next'        => 'onionsearch.next',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey), ContextScope::SHARED);
        }
    }
}
