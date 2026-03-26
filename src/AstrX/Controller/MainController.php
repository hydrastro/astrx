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
use AstrX\Template\SubPageState;

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
        // --- Config ----------------------------------------------------------
        $defaultPerPage    = (int)  $this->config->getConfig('News', 'per_page',    20);
        $defaultDescending = (bool) $this->config->getConfig('News', 'descending',  true);
        $pageWindow        = (int)  $this->config->getConfig('News', 'page_window', 3);
        $pnKey             = (string) $this->config->getConfig('News', 'pn_key',    'pn');
        $showKey           = (string) $this->config->getConfig('News', 'show_key',  'show');
        $orderKey          = (string) $this->config->getConfig('News', 'order_key', 'order');

        // --- Translatable order words ----------------------------------------
        $wordAsc  = $this->t->t('news.order.asc',  fallback: 'asc');
        $wordDesc = $this->t->t('news.order.desc', fallback: 'desc');
        $defaultOrder = $defaultDescending ? $wordDesc : $wordAsc;

        // --- Tail segment parsing (rewrite mode) -----------------------------
        $tail = $this->currentUrl->tail();

        if (isset($tail[0]) && ctype_digit($tail[0])) {
            $this->request->query()->set($pnKey, $tail[0]);
        }
        if (isset($tail[1])) {
            $segment  = strtolower($tail[1]);
            $canonical = match (true) {
                $segment === strtolower($wordAsc)  => 'asc',
                $segment === strtolower($wordDesc) => 'desc',
                default                            => null,
            };
            if ($canonical !== null) {
                $this->request->query()->set($orderKey, $canonical);
            }
        }
        if (isset($tail[2]) && ctype_digit($tail[2])) {
            $this->request->query()->set($showKey, $tail[2]);
        }

        // Comment sub-params from tail[3..6]: cp / co / cs / ci
        if (isset($tail[3]) && ctype_digit($tail[3])) {
            $this->request->query()->set('cp', $tail[3]);
        }
        if (isset($tail[4])) {
            $seg = strtolower($tail[4]);
            if ($seg === 'asc' || $seg === 'desc') {
                $this->request->query()->set('co', $seg);
            }
        }
        if (isset($tail[5]) && ctype_digit($tail[5])) {
            $this->request->query()->set('cs', $tail[5]);
        }
        if (isset($tail[6]) && ($tail[6] === '0' || $tail[6] === '1')) {
            $this->request->query()->set('ci', $tail[6]);
        }

        // Normalise the order query param regardless of routing mode.
        // The form submits locale words (e.g. 'ascendente') directly as query
        // params. Pagination::fromRequest only recognises canonical 'asc'/'desc',
        // so any locale word must be translated to canonical before that call.
        // This also re-normalises values already written above from tail segments,
        // which is harmless (canonical → canonical is a no-op in the match).
        $rawOrderParam = $this->request->query()->get($orderKey);
        if (is_string($rawOrderParam)) {
            $normalised = match (strtolower($rawOrderParam)) {
                strtolower($wordAsc)  => 'asc',
                strtolower($wordDesc) => 'desc',
                default               => null,
            };
            if ($normalised !== null) {
                $this->request->query()->set($orderKey, $normalised);
            }
        }

        // --- Build Pagination ------------------------------------------------
        $pagination = Pagination::fromRequest(
            $this->request, $defaultPerPage, $defaultDescending,
            $pnKey, $showKey, $orderKey,
        );

        // --- Data fetching ---------------------------------------------------
        $pageResult  = $this->news->fetchPage($pagination);
        $pageResult->drainTo($this->collector);
        $items = $pageResult->isOk() ? $pageResult->unwrap() : [];

        $countResult = $this->news->countVisible();
        $countResult->drainTo($this->collector);
        $total      = $countResult->isOk() ? $countResult->unwrap() : 0;
        $pagination = $pagination->withTotal($total);

        // --- URL generation --------------------------------------------------
        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;

        $localizedOrder   = $pagination->descending ? $wordDesc : $wordAsc;
        $perPage          = $pagination->perPage;

        // Register SubPageState so DefaultTemplateContext::finalise() can generate
        // all pagination URLs with full knowledge of both news and comment state.
        $this->ctx->setSubPageState(new SubPageState(
                                        resolvedUrlId:  $resolvedUrlId,
                                        page:           $pagination->page,
                                        order:          $localizedOrder,
                                        perPage:        $perPage,
                                        defaultPage:    1,
                                        defaultOrder:   $defaultOrder,
                                        defaultPerPage: $defaultPerPage,
                                        pnKey:          $pnKey,
                                        orderKey:       $orderKey,
                                        showKey:        $showKey,
                                    ));
        $this->ctx->setPagination($pagination, $pageWindow);

        // Form action: bare page URL. Browser appends order/show as query params.
        $formAction = $this->urlGenerator->toPage($resolvedUrlId);

        // --- Template vars ---------------------------------------------------
        $this->ctx->set('news_heading',      $this->t->t('news.heading'));
        $this->ctx->set('news_date',         $this->t->t('news.date'));
        $this->ctx->set('news_empty',        $this->t->t('news.empty'));
        $this->ctx->set('news_prev',         $this->t->t('news.prev'));
        $this->ctx->set('news_next',         $this->t->t('news.next'));
        $this->ctx->set('news_older',        $this->t->t('news.older'));
        $this->ctx->set('news_filter_show',  $this->t->t('news.filter.show'));
        $this->ctx->set('news_filter_order', $this->t->t('news.filter.order'));
        $this->ctx->set('news_filter_desc',  $this->t->t('news.filter.desc'));
        $this->ctx->set('news_filter_asc',   $this->t->t('news.filter.asc'));
        $this->ctx->set('news_filter_submit',$this->t->t('news.filter.submit'));
        $this->ctx->set('news_first',         $this->t->t('news.first'));
        $this->ctx->set('news_last',          $this->t->t('news.last'));

        $this->ctx->set('news_order_key',     $orderKey);
        $this->ctx->set('news_show_key',      $showKey);
        $this->ctx->set('news_word_asc',      $wordAsc);
        $this->ctx->set('news_word_desc',     $wordDesc);
        $this->ctx->set('news_form_action',   $formAction);
        // news_comment_inputs generated by DefaultTemplateContext::finalise()
        $this->ctx->set('news_desc_selected', $pagination->descending);
        $this->ctx->set('news_asc_selected',  !$pagination->descending);

        $this->ctx->set('news',          $items);
        $this->ctx->set('has_news',      $items !== []);

        // Pagination vars generated by DefaultTemplateContext::finalise() via setPagination()

        return $this->ok();
    }
}
