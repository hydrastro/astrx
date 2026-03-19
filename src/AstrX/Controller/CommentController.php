<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Captcha\CaptchaService;
use AstrX\Comment\CommentService;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\CommentPrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\AvatarService;
use AstrX\User\UserSession;

/**
 * Public comment display and submission controller.
 *
 * Injected by ContentManager after the main page controller when page.comments=1.
 *
 * KEY DESIGN DECISIONS:
 *
 * 1. Uses CommentPrgHandler (dedicated session namespace + _cp query key) instead of
 *    the shared PrgHandler. This prevents other page controllers (UserController, etc.)
 *    from consuming the comment PRG token before this controller can process it.
 *
 * 2. Comment pagination uses dedicated query params (cp/co/cs/ci) that never conflict
 *    with news params (pn/show/order). Pagination URLs preserve all non-comment query
 *    params from the current request so news pagination is not lost.
 *
 * 3. Nested comments are produced by the flat-loop + close_divs_html technique:
 *    assembleTree() gives a depth-first flat list. Each row carries close_divs_html
 *    (N closing </div> tags). Template opens one outer div per comment and never
 *    explicitly closes it — close_divs_html does that after the inner content div.
 *    Children render inside the still-open parent outer div, giving visual nesting.
 *
 * 4. All conditional sections use [$enriched] (never bool) so Mustache never
 *    clobbers the comment-row context when entering a section.
 */
final class CommentController extends AbstractController
{
    private const FORM = 'comment';

    /** Comment-specific query param names — must never collide with news params. */
    private const CP_PAGE   = 'cp'; // comment page number
    private const CP_ORDER  = 'co'; // 'asc' | 'desc'
    private const CP_SHOW   = 'cs'; // per page (int, 0=all)
    private const CP_INDENT = 'ci'; // 1=nest | 0=flat

    /** Default comment settings */
    private const DEFAULT_ORDER  = 'asc';
    private const DEFAULT_INDENT = 1;

    public function __construct(
        DiagnosticsCollector                   $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request               $request,
        private readonly Page                  $page,
        private readonly CommentService        $commentService,
        private readonly Gate                  $gate,
        private readonly UserSession           $session,
        private readonly CsrfHandler           $csrf,
        private readonly CommentPrgHandler     $commentPrg,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
        private readonly AvatarService         $avatarService,
        private readonly CaptchaService        $captchaService,
    ) {
        parent::__construct($collector);
    }

    // =========================================================================

    public function handle(): Result
    {
        // Comment submissions use _cp query key (CommentPrgHandler)
        $cpToken = $this->request->query()->get(CommentPrgHandler::QUERY_KEY);
        if (is_string($cpToken) && $cpToken !== '') {
            $this->processSubmission($cpToken);
            Response::redirect($this->pageBaseUrl())
                ->send()->drainTo($this->collector);
            exit;
        }

        $this->renderComments();
        return $this->ok();
    }

    // =========================================================================
    // Form submission
    // =========================================================================

    private function processSubmission(string $cpToken): void
    {
        $posted     = $this->commentPrg->pull($cpToken) ?? [];
        $csrfResult = $this->csrf->verify(self::FORM, (string) ($posted['_csrf'] ?? ''));
        if (!$csrfResult->isOk()) {
            $csrfResult->drainTo($this->collector);
            return;
        }

        $action = (string) ($posted['action'] ?? 'post');

        // Admin quick-moderation
        if (in_array($action, ['hide', 'unhide', 'delete'], true)) {
            if ($this->gate->can(Permission::ADMIN_COMMENTS)) {
                $id = (int) ($posted['id'] ?? 0);
                if ($id > 0) {
                    match ($action) {
                        'hide'   => $this->commentService->hide($id)->drainTo($this->collector),
                        'unhide' => $this->commentService->unhide($id)->drainTo($this->collector),
                        'delete' => $this->commentService->delete($id)->drainTo($this->collector),
                    };
                }
            }
            return;
        }

        // Captcha for guests
        if (!$this->session->isLoggedIn()) {
            $captchaResult = $this->captchaService->verify(
                (string) ($posted['captcha_id']   ?? ''),
                (string) ($posted['captcha_text'] ?? '')
            );
            if (!$captchaResult->isOk()) {
                $captchaResult->drainTo($this->collector);
                return;
            }
        }

        $content  = (string) ($posted['content']  ?? '');
        $name     = ($posted['name']  ?? '') !== '' ? (string) $posted['name']  : null;
        $email    = ($posted['email'] ?? '') !== '' ? (string) $posted['email'] : null;
        $replyTo  = is_numeric($posted['reply_to'] ?? null) ? (int) $posted['reply_to'] : null;
        $remoteIp = (string) ($this->request->server()->get('REMOTE_ADDR') ?? '');

        $this->commentService->post(
            $this->page->id, $content, $name, $email, $replyTo, $remoteIp
        )->drainTo($this->collector);
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    private function renderComments(): void
    {
        // ── Read comment display parameters from query string ─────────────────
        $pageNum    = max(1, (int) ($this->request->query()->get(self::CP_PAGE) ?? 1));
        $order      = $this->request->query()->get(self::CP_ORDER) ?? self::DEFAULT_ORDER;
        $descending = ($order === 'desc');
        $perPage    = $this->commentService->commentsPerPage();
        $csPerPage  = ($this->request->query()->get(self::CP_SHOW) !== null)
            ? max(0, (int) $this->request->query()->get(self::CP_SHOW))
            : $perPage;
        $indent     = ($this->request->query()->get(self::CP_INDENT) !== null)
            ? (int) $this->request->query()->get(self::CP_INDENT)
            : self::DEFAULT_INDENT;
        $nested     = ($indent !== 0);

        // ── Fetch comments ────────────────────────────────────────────────────
        // $csPerPage = 0 means 'show all' (no limit), just like news does.
        // We pass it explicitly to getCommentsForPage so the offset/limit are correct.
        $countResult = $this->commentService->countForPage($this->page->id);
        $countResult->drainTo($this->collector);
        $total = $countResult->isOk() ? $countResult->unwrap() : 0;

        $effectivePerPage = $csPerPage > 0 ? $csPerPage : $perPage;
        $pageCount = $effectivePerPage > 0 ? max(1, (int) ceil($total / $effectivePerPage)) : 1;
        // Clamp pageNum after we know how many pages exist
        $pageNum = min($pageNum, max(1, $pageCount));

        $commentsResult = $this->commentService->getCommentsForPage(
                     $this->page->id, $pageNum, $descending,
            itemId: null,
            perPage: $csPerPage > 0 ? $csPerPage : null,
        );
        $commentsResult->drainTo($this->collector);
        $flat = $commentsResult->isOk() ? $commentsResult->unwrap() : [];

        // When flat mode: zero out depths so close_divs_html is always '</div>'
        if (!$nested) {
            foreach ($flat as &$row) { $row['depth'] = 0; }
            unset($row);
        }

        // ── Build comment pagination URLs ─────────────────────────────────────
        // Preserve all current query params EXCEPT comment-specific ones.
        // This keeps news pagination params (pn, show, order) in the URL.
        $commentKeys = [self::CP_PAGE, self::CP_ORDER, self::CP_SHOW, self::CP_INDENT,
                        CommentPrgHandler::QUERY_KEY, 'reply_to'];
        $baseQuery   = $this->currentNonCommentQuery($commentKeys);
        $pageBase    = $this->pageBaseUrl();

        $commentUrlFor = function (
            int $p,
            ?string $ord  = null,
            ?int    $show = null,
            ?int    $ind  = null,
        ) use ($pageBase, $baseQuery, $order, $csPerPage, $indent, $effectivePerPage, $perPage): string {
            $q = $baseQuery;
            if ($p > 1)                               { $q[self::CP_PAGE]   = $p; }
            $o = $ord  ?? $order;
            $s = $show ?? $csPerPage;
            $i = $ind  ?? $indent;
            if ($o !== self::DEFAULT_ORDER)           { $q[self::CP_ORDER]  = $o; }
            if ($s !== $perPage)                      { $q[self::CP_SHOW]   = $s; }
            if ($i !== self::DEFAULT_INDENT)          { $q[self::CP_INDENT] = $i; }
            return $q !== [] ? $pageBase . '?' . http_build_query($q)
                : $pageBase;
        };

        $pages = [];
        for ($p = 1; $p <= $pageCount; $p++) {
            $url     = $p !== $pageNum ? $commentUrlFor($p) : '';
            $pages[] = [
                'number'     => $p,
                'url'        => $url,
                'is_current' => $p === $pageNum,
                'link'       => $url !== ''
                    ? '<a href="' . htmlspecialchars($url) . '">' . $p . '</a>'
                    : '',
            ];
        }

        // ── Enrich each row ───────────────────────────────────────────────────
        $isAdmin       = $this->gate->can(Permission::ADMIN_COMMENTS);
        $allowReplies  = $this->commentService->allowReplies();
        $useIdenticons = $this->avatarService->useIdenticons();
        $profileBase   = $this->urlGen->toPage(
            $this->t->t('WORDING_PROFILE', fallback: 'WORDING_PROFILE')
        );

        // Build reply_to pre-fill URL (strips reply_to, keeps other params)
        $replyToPreFill  = (int) ($this->request->query()->get('reply_to') ?? 0);
        $cancelReplyUrl  = $pageBase . ($baseQuery !== [] ? '?' . http_build_query($baseQuery) : '');

        $comments = [];
        foreach ($flat as $i => $row) {
            $isHidden = (bool) ($row['hidden'] ?? false);
            $replyTo  = isset($row['reply_to']) && (int) $row['reply_to'] > 0
                ? (int) $row['reply_to'] : null;

            if ($row['user_id'] !== null) {
                $displayName = (string) ($row['user_display_name'] ?? $row['name'] ?? 'Anonymous');
                $avatarSrc   = $this->urlGen->toPage('avatar', ['uid' => (string) $row['user_id']]);
                $profileUrl  = $profileBase . '?uid=' . rawurlencode((string) $row['user_id']);
            } else {
                $displayName = ($row['name'] ?? '') !== '' ? (string) $row['name'] : 'Anonymous';
                $profileUrl  = '';
                $avatarSrc   = $useIdenticons
                    ? $this->urlGen->toPage('avatar', [
                        'seed' => hash('sha256', ($row['name'] ?? '') . ($row['ip'] ?? '')),
                    ])
                    : '';
            }

            $enriched = $row + [
                    'display_name' => $displayName,
                    'avatar_src'   => $avatarSrc,
                    'profile_url'  => $profileUrl,
                    'row_opacity'  => $isHidden ? 'opacity:0.5;' : '',
                    'is_own'       => $this->session->isLoggedIn()
                                      && $row['user_id'] === $this->session->userId(),
                ];

            // reply_url MUST be set before the section wrappers below.
            // The snapshot [$enriched] is taken by value — any key added after
            // reply_section is assigned will NOT appear inside {{#reply_section}}.
            $replyBase = $commentUrlFor(1);
            $replySep  = str_contains($replyBase, '?') ? '&' : '?';
            $enriched['reply_url'] = $replyBase . $replySep
                                     . 'reply_to=' . (int) $row['id'] . '#comment_form';

            // ── Section wrappers ──────────────────────────────────────────────
            // RULE: no section that reads row fields may be nested inside another.
            // Every conditional is a separate top-level key → [$enriched] or false.
            $hasAvatar  = ($avatarSrc !== '');
            $hasProfile = ($profileUrl !== '');
            $enriched['avatar_profile_section'] = ($hasAvatar && $hasProfile)  ? [$enriched] : false;
            $enriched['avatar_plain_section']   = ($hasAvatar && !$hasProfile) ? [$enriched] : false;
            $enriched['name_profile_section']   = $hasProfile                  ? [$enriched] : false;
            $enriched['name_plain_section']      = !$hasProfile                ? [$enriched] : false;
            $enriched['reply_to_section']        = ($replyTo !== null)          ? [$enriched] : false;
            $enriched['reply_section']           = $allowReplies                ? [$enriched] : false;
            $enriched['admin_hide_section']      = ($isAdmin && !$isHidden)    ? [$enriched] : false;
            $enriched['admin_unhide_section']    = ($isAdmin && $isHidden)     ? [$enriched] : false;
            $enriched['admin_delete_section']    = $isAdmin                    ? [$enriched] : false;

            // close_divs_html: closes outer comment div(s) to produce nesting
            $d  = (int) ($row['depth'] ?? 0);
            $nd = isset($flat[$i + 1]) ? (int) ($flat[$i + 1]['depth'] ?? 0) : -1;
            if ($nd > $d)       { $close = 0; }
            elseif ($nd === $d) { $close = 1; }
            elseif ($nd >= 0)   { $close = $d - $nd + 1; }
            else                { $close = $d + 1; }
            $enriched['close_divs_html'] = str_repeat('</div>', $close);

            $comments[] = $enriched;
        }

        // ── Pagination ────────────────────────────────────────────────────────
        $hasPagination = ($pageCount > 1);
        $prevUrl = $pageNum > 1           ? $commentUrlFor($pageNum - 1) : '';
        $nextUrl = $pageNum < $pageCount  ? $commentUrlFor($pageNum + 1) : '';

        // ── Filter form: order/indent URLs ────────────────────────────────────
        $toAscUrl  = $commentUrlFor(1, 'asc',  $csPerPage, $indent);
        $toDescUrl = $commentUrlFor(1, 'desc', $csPerPage, $indent);
        $toNestUrl = $commentUrlFor(1, $order, $csPerPage, 1);
        $toFlatUrl = $commentUrlFor(1, $order, $csPerPage, 0);
        // Form action for the show-count form (GET form, browser adds cs=N)
        $filterFormAction = $pageBase;

        // ── PRG setup ─────────────────────────────────────────────────────────
        $csrfToken = $this->csrf->generate(self::FORM);
        $prgId     = $this->commentPrg->createId($pageBase);

        // Captcha for guests
        $showCaptcha  = false;
        $captchaId    = '';
        $captchaImage = '';
        if (!$this->session->isLoggedIn()) {
            $captchaGen = $this->captchaService->generate();
            if ($captchaGen->isOk()) {
                ['id' => $captchaId, 'image_b64' => $captchaImage] = $captchaGen->unwrap();
                $showCaptcha = true;
            }
        }

        // ── Pass vars to template ─────────────────────────────────────────────

        // Current filter state for form defaults
        $this->ctx->set('comments_order_asc',    !$descending);
        $this->ctx->set('comments_order_desc',   $descending);
        $this->ctx->set('comments_indent_on',    $nested);
        $this->ctx->set('comments_indent_off',   !$nested);
        $this->ctx->set('comments_per_page_val', $csPerPage);

        // Filter URLs
        $this->ctx->set('comments_to_asc_url',   $toAscUrl);
        $this->ctx->set('comments_to_desc_url',  $toDescUrl);
        $this->ctx->set('comments_to_nest_url',  $toNestUrl);
        $this->ctx->set('comments_to_flat_url',  $toFlatUrl);
        $this->ctx->set('comments_filter_action',$filterFormAction);

        // Pass all current non-comment query params as hidden inputs for the filter form
        $this->ctx->set('comments_base_query_inputs', $this->buildHiddenInputs($baseQuery));

        // Pagination
        $this->ctx->set('comments_any',          $comments !== []);
        $this->ctx->set('comments',              $comments);
        $this->ctx->set('comments_count',        $total);
        $this->ctx->set('comments_pages',        $pages);
        $this->ctx->set('has_pagination',        $hasPagination);
        $this->ctx->set('comments_prev_url',     $prevUrl);
        $this->ctx->set('comments_next_url',     $nextUrl);
        $this->ctx->set('comments_has_prev',     $prevUrl !== '');
        $this->ctx->set('comments_has_next',     $nextUrl !== '');

        // Reply pre-fill
        $this->ctx->set('comments_reply_to',     $replyToPreFill > 0 ? $replyToPreFill : '');
        $this->ctx->set('comments_cancel_reply_url', $cancelReplyUrl . '#comment_form');

        // Form
        $this->ctx->set('comments_csrf',         $csrfToken);
        $this->ctx->set('comments_prg_id',       $prgId);
        $this->ctx->set('comments_logged_in',    $this->session->isLoggedIn());
        $this->ctx->set('show_captcha',          $showCaptcha);
        $this->ctx->set('captcha_id',            $captchaId);
        $this->ctx->set('captcha_image',         $captchaImage);

        // Labels
        $this->ctx->set('comments_heading',           $this->t->t('comment.heading'));
        $this->ctx->set('comments_none',              $this->t->t('comment.none'));
        $this->ctx->set('comments_submit_heading',    $this->t->t('comment.submit_heading'));
        $this->ctx->set('comment_label_show',         $this->t->t('comment.label.show'));
        $this->ctx->set('comment_label_order',        $this->t->t('comment.label.order'));
        $this->ctx->set('comment_label_order_asc',    $this->t->t('comment.label.order_asc'));
        $this->ctx->set('comment_label_order_desc',   $this->t->t('comment.label.order_desc'));
        $this->ctx->set('comment_label_indent',       $this->t->t('comment.label.indent'));
        $this->ctx->set('comment_label_indent_nest',  $this->t->t('comment.label.indent_nest'));
        $this->ctx->set('comment_label_indent_flat',  $this->t->t('comment.label.indent_flat'));
        $this->ctx->set('comment_label_name',         $this->t->t('comment.label.name'));
        $this->ctx->set('comment_label_email',        $this->t->t('comment.label.email'));
        $this->ctx->set('comment_label_content',      $this->t->t('comment.label.content'));
        $this->ctx->set('comment_label_reply',        $this->t->t('comment.label.reply'));
        $this->ctx->set('comment_label_captcha',      $this->t->t('comment.label.captcha'));
        $this->ctx->set('comment_btn_submit',         $this->t->t('comment.btn.submit'));
        $this->ctx->set('comment_btn_filter',         $this->t->t('comment.btn.filter'));
        $this->ctx->set('comment_btn_reply',          $this->t->t('comment.btn.reply'));
        $this->ctx->set('comment_btn_cancel_reply',   $this->t->t('comment.btn.cancel_reply'));
        $this->ctx->set('comment_btn_hide',           $this->t->t('comment.btn.hide'));
        $this->ctx->set('comment_btn_unhide',         $this->t->t('comment.btn.unhide'));
        $this->ctx->set('comment_btn_delete',         $this->t->t('comment.btn.delete'));
        $this->ctx->set('comment_word_older',         $this->t->t('comment.word.older'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Translated bare page URL (no query params). */
    private function pageBaseUrl(): string
    {
        return $this->urlGen->toPage(
            $this->t->t($this->page->urlId, fallback: $this->page->urlId)
        );
    }

    /**
     * All current query params EXCEPT the given keys and internal routing keys.
     * Used to build comment pagination URLs that preserve news pagination etc.
     * @param list<string> $strip
     * @return array<string,string>
     */
    private function currentNonCommentQuery(array $strip): array
    {
        // Keys managed by the routing layer — never put these in pagination URLs
        $routingKeys = ['lang', 'page', '_sid', '_prg', '_cp', 'prg_id'];
        $exclude     = array_merge($routingKeys, $strip);
        $result      = [];
        foreach ($this->request->query()->all() as $k => $v) {
            if (!in_array($k, $exclude, true)) {
                $result[(string) $k] = (string) $v;
            }
        }
        return $result;
    }

    /**
     * Build a string of <input type="hidden"> elements for every entry in $params.
     * Used in the comment filter form to forward non-comment query params.
     */
    private function buildHiddenInputs(array $params): string
    {
        $out = '';
        foreach ($params as $k => $v) {
            $k    = htmlspecialchars((string) $k, ENT_QUOTES);
            $v    = htmlspecialchars((string) $v, ENT_QUOTES);
            $out .= "<input type=\"hidden\" name=\"{$k}\" value=\"{$v}\">\n";
        }
        return $out;
    }
}