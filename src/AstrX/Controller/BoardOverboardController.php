<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\I18n\Translator;
use AstrX\Imageboard\BoardView;
use AstrX\Imageboard\ImageService;
use AstrX\Imageboard\PostRepository;
use AstrX\Imageboard\ThreadRepository;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Template\DefaultTemplateContext;
use function AstrX\Support\langDir;

/**
 * Overboard — a combined catalog of the newest active threads across every
 * active board (WORDING_BOARD_OVERBOARD, file_name 'board_overboard',
 * template=1). Read-only, no posting, no JavaScript. Gated by BOARD_VIEW.
 *
 * Each thread is fetched with its OP and OP thumbnail and rendered as a catalog
 * cell via the shared BoardView::catalog(); the owning board's slug is folded
 * into the cell (as a "/slug/ " subject prefix) and each cell links to that
 * board's thread. Tor-safe: no external requests, only site-relative URLs.
 */
final class BoardOverboardController extends AbstractController
{
    /** How many threads the overboard shows at once. */
    private const OVERBOARD_LIMIT = 60;

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Gate                   $gate,
        private readonly Translator             $t,
        private readonly UrlGenerator           $urlGen,
        private readonly ThreadRepository       $threads,
        private readonly PostRepository         $posts,
        private readonly ImageService           $images,
        private readonly BoardView              $view,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Imageboard');

        if ($this->gate->cannot(Permission::BOARD_VIEW)) {
            http_response_code(404);
            exit;
        }

        $tr   = $this->threads->newestAcrossBoards(self::OVERBOARD_LIMIT);
        $rows = $tr->isOk() ? $tr->unwrap() : [];

        // Batch-load every OP, then every OP's images, in two queries total.
        $tids = [];
        foreach ($rows as $row) {
            $tids[] = self::mInt($row, 'id');
        }
        $opR = $this->posts->opsForThreads($tids);
        $ops = $opR->isOk() ? $opR->unwrap() : [];
        $opIds = [];
        foreach ($ops as $op) {
            $opIds[] = self::mInt($op, 'id');
        }
        $imagesByPost = $this->images->forPosts($opIds);

        $cells = [];
        foreach ($rows as $row) {
            $tid  = self::mInt($row, 'id');
            $slug = self::mStr($row, 'board_slug');
            $op   = $ops[$tid] ?? null;

            $thumb   = '';
            $excerpt = '';
            if (is_array($op)) {
                foreach ($imagesByPost[self::mInt($op, 'id')] ?? [] as $im) {
                    // Videos carry no server-side thumbnail — skip to the first image.
                    if (!str_starts_with(self::mStr($im, 'mime'), 'video/')) {
                        $thumb = $this->fileUrl(self::mStr($im, 'token'), true);
                        break;
                    }
                }
                $excerpt = mb_strimwidth(strip_tags(self::mStr($op, 'body_html')), 0, 140, '…');
            }

            // Fold the owning board into the cell so the grid stays legible: a
            // "/slug/ " prefix on the subject doubles as the board tag and always
            // renders (BoardView::catalog only shows a non-empty subject).
            $subject = self::mStr($row, 'subject');
            $tagged  = '/' . $slug . '/ ' . $subject;

            $cells[] = [
                'thread_url'  => $this->threadUrl($slug, $tid),
                'subject'     => $tagged,
                'reply_count' => self::mInt($row, 'reply_count'),
                'image_count' => self::mInt($row, 'image_count'),
                'thumb_url'   => $thumb,
                'has_thumb'   => $thumb !== '',
                'excerpt'     => $excerpt,
            ];
        }

        $this->ctx->set('overboard_html', $this->view->catalog($cells));
        $this->ctx->set('has_overboard',  $cells !== []);
        $this->setLabels();
        return $this->ok();
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_overboard_heading' => 'board.overboard_heading',
            'lbl_overboard_sub'     => 'board.overboard_sub',
            'lbl_overboard_empty'   => 'board.overboard_empty',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }

    private function threadUrl(string $slug, int $tid): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_BOARD'))
            . '/' . rawurlencode($slug) . '/thread/' . $tid;
    }

    private function fileUrl(string $token, bool $thumb): string
    {
        $base = $this->urlGen->toPage($this->t->t('WORDING_BOARD_FILE'));
        return $base . '?t=' . rawurlencode($token) . ($thumb ? '&thumb=1' : '');
    }
}
