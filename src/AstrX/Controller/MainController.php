<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Config\Config;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\News\NewsRepository;
use AstrX\Page\Page;
use AstrX\Pagination\Pagination;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;

final class MainController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly NewsRepository        $news,
        private readonly Config                $config,
        private readonly Translator            $t,
        private readonly UrlGenerator          $urlGenerator,
        private readonly Page                  $page,
        private readonly CurrentUrl            $currentUrl,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        $defaultPerPage    = (int)  $this->config->getConfig('News', 'per_page',   20);
        $defaultDescending = (bool) $this->config->getConfig('News', 'descending', true);
        $defaultOrder      = $defaultDescending ? 'desc' : 'asc';

        // --- URL sub-param parsing -------------------------------------------
        //
        // Rewrite mode: remaining path segments after locale + page token are
        // stored in CurrentUrl::tail() by ContentManager.
        //   /en/main/3/asc/10 → tail = ['3', 'asc', '10']
        //
        // Query mode: the browser sends named params directly, e.g.:
        //   ?pn=3&order=asc&show=10
        //
        // In both cases we write the resolved values into Request::query()
        // under the keys Pagination::fromRequest() reads, so the same
        // fromRequest() call works regardless of routing mode.
        //
        // The GET filter form always submits 'order' and 'show' as query
        // params regardless of routing mode — both of which Pagination reads
        // directly from Request::query(), no tail mapping needed.

        $tail = $this->currentUrl->tail();

        // Positional mapping: [0] = page number, [1] = order, [2] = show
        if (isset($tail[0]) && ctype_digit($tail[0])) {
            $this->request->query()->set('pn', $tail[0]);
        }
        if (isset($tail[1]) && in_array(strtolower($tail[1]), ['asc', 'desc'], true)) {
            $this->request->query()->set('order', strtolower($tail[1]));
        }
        if (isset($tail[2]) && ctype_digit($tail[2])) {
            $this->request->query()->set('show', $tail[2]);
        }

        // Build pagination from request (reads pn/order/show).
        $pagination = Pagination::fromRequest($this->request, $defaultPerPage, $defaultDescending);

        // --- Data fetching ---------------------------------------------------
        $pageResult = $this->news->fetchPage($pagination);
        $pageResult->drainTo($this->collector);
        $items = $pageResult->isOk() ? ($pageResult->unwrap() ?? []) : [];

        $countResult = $this->news->countVisible();
        $countResult->drainTo($this->collector);
        $total      = $countResult->isOk() ? ($countResult->unwrap() ?? 0) : 0;
        $pagination = $pagination->withTotal($total);

        // --- URL generation --------------------------------------------------
        // Resolve current page slug for link building.
        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;

        // Closure passed to toTemplateVars: builds the URL for a given page
        // number, carrying current order and perPage through each link.
        $order   = $pagination->order;
        $perPage = $pagination->perPage;
        $urlForPage = fn(int $p): string => $this->urlGenerator->toSubPage(
            resolvedUrlId:   $resolvedUrlId,
            page:            $p,
            order:           $order,
            perPage:         $perPage,
            defaultPage:     1,
            defaultOrder:    $defaultOrder,
            defaultPerPage:  $defaultPerPage,
        );

        // Form action — bare page URL, no sub-params.
        // The GET form appends 'order' and 'show' as query params directly.
        // Submitting resets to page 1 (correct: filter change → back to start).
        $formAction = $this->urlGenerator->toPage($resolvedUrlId);

        // --- Template vars ---------------------------------------------------
        $this->ctx->set('news_heading',      $this->t->t('news.heading'));
        $this->ctx->set('news_date',         $this->t->t('news.date'));
        $this->ctx->set('news_empty',        $this->t->t('news.empty'));
        $this->ctx->set('news_prev',         $this->t->t('news.prev'));
        $this->ctx->set('news_next',         $this->t->t('news.next'));
        $this->ctx->set('news_page',         $this->t->t('news.page'));
        $this->ctx->set('news_filter_show',  $this->t->t('news.filter.show'));
        $this->ctx->set('news_filter_order', $this->t->t('news.filter.order'));
        $this->ctx->set('news_filter_desc',  $this->t->t('news.filter.desc'));
        $this->ctx->set('news_filter_asc',   $this->t->t('news.filter.asc'));
        $this->ctx->set('news_filter_submit',$this->t->t('news.filter.submit'));

        $this->ctx->set('news_form_action',  $formAction);
        $this->ctx->set('news_order',        $order);
        $this->ctx->set('news_desc_selected',$pagination->descending);
        $this->ctx->set('news_asc_selected', !$pagination->descending);

        $this->ctx->set('news',     $items);
        $this->ctx->set('has_news', $items !== []);

        foreach ($pagination->toTemplateVars($urlForPage) as $k => $v) {
            $this->ctx->set($k, $v);
        }

        return $this->ok();
    }
}