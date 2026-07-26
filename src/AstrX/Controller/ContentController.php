<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Content\ContentPageRepository;
use AstrX\Content\ContentService;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Public content pages (W/wcms-inspired). One route, three modes:
 *
 *   /pages               → index: list of visible content pages
 *   /pages?view=graph    → the static-SVG page graph
 *   /pages/<slug>        → a single Markdown page (with a "what links here" panel)
 *
 * Seeded with file_name 'content' so the reflection router resolves it here; slug
 * WORDING_CONTENT ('pages'). Public: gated by NEWS_VIEW (granted to guests). All
 * rendering + link resolution lives in {@see ContentService}; this controller
 * only picks the mode and shapes the view model.
 */
final class ContentController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly CurrentUrl             $currentUrl,
        private readonly ContentPageRepository  $repo,
        private readonly ContentService         $service,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Content');

        if ($this->gate->cannot(Permission::NEWS_VIEW)) {
            http_response_code(404);
            exit;
        }

        $slug = self::str($this->currentUrl->tailSegment(0));
        $view = self::queryStr($this->request, 'view');

        if ($slug !== '') {
            $this->renderView($slug);
        } elseif ($view === 'graph') {
            $this->renderGraph();
        } else {
            $this->renderIndex();
        }

        $this->ctx->set('index_url', $this->service->indexUrl());
        $this->ctx->set('graph_url', $this->service->graphUrl());
        $this->setLabels();
        return $this->ok();
    }

    private function renderView(string $slug): void
    {
        $r    = $this->repo->bySlug($slug)->drainTo($this->collector);
        $page = $r->isOk() ? $r->unwrap() : null;
        $isAdmin = $this->gate->can(Permission::ADMIN_ACCESS);

        if ($page === null || (!$page['visible'] && !$isAdmin)) {
            http_response_code(404);
            $this->ctx->set('is_missing',  true);
            $this->ctx->set('missing_slug', $slug);
            $this->ctx->set('title', $this->t->t('content.not_found'));
            return;
        }

        $title = $page['title'] !== '' ? $page['title'] : $slug;
        $backlinks = $this->service->backlinks($page['id']);

        $this->ctx->set('is_view',       true);
        $this->ctx->set('title',         $title);       // document <title>
        $this->ctx->set('page_title',    $title);
        $this->ctx->set('page_html',     $this->service->renderBody($page['body']));
        $this->ctx->set('updated_at',    $page['updated_at']);
        $this->ctx->set('page_hidden',   !$page['visible']);   // admin preview of an unlisted page
        $this->ctx->set('backlinks',     $backlinks);
        $this->ctx->set('has_backlinks', $backlinks !== []);
    }

    private function renderGraph(): void
    {
        $graph = $this->service->graphSvg();
        $this->ctx->set('is_graph',  true);
        $this->ctx->set('graph_svg', $graph['svg']);
        $this->ctx->set('graph_empty', $graph['count'] === 0);
        $this->ctx->set('title', $this->t->t('content.graph_heading'));
    }

    private function renderIndex(): void
    {
        $pages = $this->service->index();
        $this->ctx->set('is_index',   true);
        $this->ctx->set('pages',      $pages);
        $this->ctx->set('has_pages',  $pages !== []);
        $this->ctx->set('title', $this->t->t('content.index_heading'));
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_index_heading' => 'content.index_heading',
            'lbl_graph_heading' => 'content.graph_heading',
            'lbl_graph_link'    => 'content.graph_link',
            'lbl_index_link'    => 'content.index_link',
            'lbl_empty'         => 'content.empty',
            'lbl_graph_empty'   => 'content.graph_empty',
            'lbl_backlinks'     => 'content.backlinks',
            'lbl_not_found'     => 'content.not_found',
            'lbl_not_found_msg' => 'content.not_found_msg',
            'lbl_updated'       => 'content.updated',
            'lbl_unlisted'      => 'content.unlisted',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}
