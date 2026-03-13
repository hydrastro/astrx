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
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        $defaultPerPage    = (int)  $this->config->getConfig('News', 'per_page',   20);
        $defaultDescending = (bool) $this->config->getConfig('News', 'descending', true);

        $pagination = Pagination::fromRequest($this->request, $defaultPerPage, $defaultDescending);

        // Fetch page — non-fatal: renders with empty list on DB error
        $pageResult = $this->news->fetchPage($pagination);
        $pageResult->drainTo($this->collector);
        $items = $pageResult->isOk() ? ($pageResult->unwrap() ?? []) : [];

        // Fetch total for page count — non-fatal
        $countResult = $this->news->countVisible();
        $countResult->drainTo($this->collector);
        $total      = $countResult->isOk() ? ($countResult->unwrap() ?? 0) : 0;
        $pagination = $pagination->withTotal($total);

        // Resolve the current page's URL slug for pagination link generation.
        // Page::urlId may be a WORDING_ key (i18n=true) or a plain slug (i18n=false).
        $resolvedUrlId = $this->page->i18n
            ? $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            : $this->page->urlId;

        // i18n labels — resolved here so the template stays logic-free.
        // These keys live in lang/{locale}/Main.php, loaded by ContentManager
        // before this controller runs (via ucfirst($page->fileName)).
        $this->ctx->set('news_heading', $this->t->t('news.heading'));
        $this->ctx->set('news_title', $this->t->t('news.title'));
        $this->ctx->set('news_date',  $this->t->t('news.date'));
        $this->ctx->set('news_empty', $this->t->t('news.empty'));
        $this->ctx->set('news_prev',  $this->t->t('news.prev'));
        $this->ctx->set('news_next',  $this->t->t('news.next'));
        $this->ctx->set('news_page',  $this->t->t('news.page'));

        $this->ctx->set('news',     $items);
        $this->ctx->set('has_news', $items !== []);

        // Merge pagination template vars (page, page_count, has_prev, has_next,
        // prev_url, next_url, per_page) into the context.
        foreach ($pagination->toTemplateVars($this->urlGenerator, $resolvedUrlId) as $key => $value) {
            $this->ctx->set($key, $value);
        }

        return $this->ok();
    }
}