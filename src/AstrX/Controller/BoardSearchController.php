<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\Imageboard\BoardNav;
use AstrX\Imageboard\BoardRepository;
use AstrX\Imageboard\PostRepository;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Post/thread search — a no-JavaScript GET form over post subject/body with an
 * optional per-board filter (WORDING_BOARD_SEARCH, file_name 'board_search',
 * template=1). Read-only. Gated by BOARD_VIEW.
 *
 * The query is a plain <form method="get"> with a `q` field (and an optional
 * `board` dropdown), so results are a bookmarkable GET URL. Matching is a bound
 * LIKE substring over body_raw/subject in PostRepository::search — the term is
 * never interpolated. Each hit links to its thread and shows a plain-text
 * excerpt. Tor-safe: no external requests, only site-relative URLs.
 */
final class BoardSearchController extends AbstractController
{
    /** Maximum hits returned for one query. */
    private const SEARCH_LIMIT = 50;

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly PostRepository         $posts,
        private readonly BoardRepository        $boards,
        private readonly BoardNav               $nav,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Imageboard');
        $this->ctx->set('board_nav_show', true);
        $this->ctx->set('board_top_nav', $this->nav->topNav('search'));

        if ($this->gate->cannot(Permission::BOARD_VIEW)) {
            http_response_code(404);
            exit;
        }

        $q         = self::queryStr($this->request, 'q');
        $filterRaw = self::queryStr($this->request, 'board');

        // Resolve the optional board filter to an id (an unknown slug simply
        // clears the filter and searches every board).
        $filterId   = null;
        $filterSlug = '';
        if ($filterRaw !== '') {
            $bR    = $this->boards->bySlug($filterRaw);
            $board = $bR->isOk() ? $bR->unwrap() : null;
            if (is_array($board)) {
                $filterId   = self::mInt($board, 'id');
                $filterSlug = self::mStr($board, 'slug');
            }
        }

        $this->buildBoardOptions($filterSlug);

        $results  = [];
        $searched = $q !== '';
        if ($searched) {
            $sR   = $this->posts->search($q, $filterId, self::SEARCH_LIMIT);
            $rows = $sR->isOk() ? $sR->unwrap() : [];
            foreach ($rows as $row) {
                $slug = self::mStr($row, 'board_slug');
                $no   = self::mInt($row, 'no');
                $tid  = self::mInt($row, 'thread_id');
                $results[] = [
                    'board_slug' => $slug,
                    'no'         => $no,
                    'subject'    => self::mStr($row, 'subject'),
                    'excerpt'    => mb_strimwidth(strip_tags(self::mStr($row, 'body_html')), 0, 200, '…'),
                    'thread_url' => $this->threadUrl($slug, $tid) . '#p' . $no,
                ];
            }
        }

        $this->ctx->set('form_action', $this->urlGen->toPage($this->t->t('WORDING_BOARD_SEARCH')));
        $this->ctx->set('q',           $q);
        $this->ctx->set('searched',    $searched);
        // 'search_results', NOT 'results' — DefaultTemplateContext reserves
        // 'results' as a legacy alias for the diagnostic message list and
        // overwrites it in finalise() (after this controller), which would blank
        // the rows while the count still rendered.
        $this->ctx->set('search_results', $results);
        $this->ctx->set('has_results', $results !== []);
        $this->setLabels();
        return $this->ok();
    }

    /**
     * Build the optional board-filter dropdown from the active boards, flagging
     * the currently-selected one so the template can pre-select it.
     */
    private function buildBoardOptions(string $selectedSlug): void
    {
        $lr   = $this->boards->listActive();
        $rows = $lr->isOk() ? $lr->unwrap() : [];
        $list = [];
        foreach ($rows as $row) {
            $slug   = self::mStr($row, 'slug');
            $list[] = [
                'slug'  => $slug,
                'title' => self::mStr($row, 'title'),
                'sel'   => $slug === $selectedSlug,
            ];
        }
        $this->ctx->set('boards',     $list);
        $this->ctx->set('has_boards', $list !== []);
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_search_heading'    => 'board.search_heading',
            'lbl_search_query'      => 'board.search_query',
            'lbl_search_board'      => 'board.search_board',
            'lbl_search_all_boards' => 'board.search_all_boards',
            'lbl_search_submit'     => 'board.search_submit',
            'lbl_search_no_results' => 'board.search_no_results',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }

    private function threadUrl(string $slug, int $tid): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_BOARD'))
            . '/' . rawurlencode($slug) . '/thread/' . $tid;
    }
}
