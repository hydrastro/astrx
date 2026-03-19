<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Comment\CommentService;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserSession;

/**
 * Public comment display and submission controller.
 *
 * This controller is NOT the main controller for any page — it is included
 * as a sub-component by pages that have comments enabled (page.comments = 1).
 * ContentManager calls it after the primary controller when page.comments = 1.
 *
 * PRG flow (same pattern as user forms):
 *   GET  → render existing comments + empty form (with CSRF)
 *   POST → ContentManager intercepts, stores in PRG, redirects
 *   GET ?_prg=token → pull, validate, submit, redirect back
 *
 * The page template includes {{> comments}} which renders comments.html,
 * a partial that displays the list and the submission form.
 * All comment vars are set under the 'comments_*' namespace.
 */
final class CommentController extends AbstractController
{
    private const FORM = 'comment';

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly Page                  $page,
        private readonly CommentService        $commentService,
        private readonly UserSession           $session,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        // Process PRG submission
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processSubmission($prgToken);
            // Redirect back to the page (strip _prg from URL)
            $pageUrl = $this->urlGen->toPage(
                $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            );
            Response::redirect($pageUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->renderComments();
        return $this->ok();
    }

    // -------------------------------------------------------------------------

    private function processSubmission(string $prgToken): void
    {
        $posted = $this->prg->pull($prgToken) ?? [];

        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $content = (string) ($posted['content'] ?? '');
        $name    = ($posted['name']  ?? '') !== '' ? (string) $posted['name']  : null;
        $email   = ($posted['email'] ?? '') !== '' ? (string) $posted['email'] : null;
        $replyTo = is_numeric($posted['reply_to'] ?? null) ? (int) $posted['reply_to'] : null;

        $remoteIp = (string) ($this->request->server()->get('REMOTE_ADDR') ?? '');

        $result = $this->commentService->post(
            $this->page->id, $content, $name, $email, $replyTo, $remoteIp
        );
        $result->drainTo($this->collector);
    }

    private function renderComments(): void
    {
        $pageNum     = max(1, (int) ($this->request->query()->get('cp') ?? 1));
        $descending  = $this->request->query()->get('cd') !== '0';

        $commentsResult = $this->commentService->getCommentsForPage(
            $this->page->id, $pageNum, $descending
        );
        $commentsResult->drainTo($this->collector);

        $countResult = $this->commentService->countForPage($this->page->id);
        $countResult->drainTo($this->collector);

        $total       = $countResult->isOk() ? $countResult->unwrap() : 0;
        $perPage     = $this->commentService->commentsPerPage();
        $pageCount   = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
        $rawComments = $commentsResult->isOk() ? $commentsResult->unwrap() : [];

        // User display_name and avatar flag are now JOINed in the query — no per-comment
        // user lookup needed. One query serves all comments.
        $comments = [];
        foreach ($rawComments as $row) {
            if ($row['user_id'] !== null) {
                // Registered user: use joined data
                $displayName = (string) ($row['user_display_name'] ?? $row['name'] ?? 'Anonymous');
                $avatarSrc   = $this->urlGen->toPage('avatar', ['uid' => (string) $row['user_id']]);
            } else {
                // Anonymous
                $displayName = ($row['name'] ?? '') !== '' ? (string) $row['name'] : 'Anonymous';
                $avatarSrc   = '';
            }
            $comments[] = $row + [
                    'display_name' => $displayName,
                    'avatar_src'   => $avatarSrc,
                    'indent_style' => 'margin-left:' . ((int) $row['depth'] * 24) . 'px',
                    'is_own'       => $this->session->isLoggedIn()
                                      && $row['user_id'] === $this->session->userId(),
                ];
        }

        // Pagination URLs
        $baseUrl = $this->urlGen->toPage(
            $this->t->t($this->page->urlId, fallback: $this->page->urlId)
        );
        $pages = [];
        for ($p = 1; $p <= $pageCount; $p++) {
            $pages[] = [
                'number'    => $p,
                'url'       => $baseUrl . '?cp=' . $p,
                'is_current'=> $p === $pageNum,
            ];
        }

        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->prg->createId($baseUrl);

        $this->ctx->set('comments',              $comments);
        $this->ctx->set('comments_count',        $total);
        $this->ctx->set('comments_pages',        $pages);
        $this->ctx->set('comments_page_current', $pageNum);
        $this->ctx->set('comments_allow_replies',$this->commentService->allowReplies());
        $this->ctx->set('comments_require_email',$this->commentService->requireEmail());
        $this->ctx->set('comments_logged_in',    $this->session->isLoggedIn());
        $this->ctx->set('comments_csrf',         $csrfToken);
        $this->ctx->set('comments_prg_id',       $prgId);
        $this->ctx->set('comments_heading',      $this->t->t('comment.heading'));
        $this->ctx->set('comments_none',         $this->t->t('comment.none'));
        $this->ctx->set('comments_submit_heading',$this->t->t('comment.submit_heading'));
        $this->ctx->set('comment_label_name',    $this->t->t('comment.label.name'));
        $this->ctx->set('comment_label_email',   $this->t->t('comment.label.email'));
        $this->ctx->set('comment_label_content', $this->t->t('comment.label.content'));
        $this->ctx->set('comment_label_reply',   $this->t->t('comment.label.reply'));
        $this->ctx->set('comment_btn_submit',    $this->t->t('comment.btn.submit'));
        $this->ctx->set('comment_btn_reply',     $this->t->t('comment.btn.reply'));
    }
}