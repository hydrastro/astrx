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
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\AvatarService;
use AstrX\User\UserSession;

/**
 * Public comment display and submission controller.
 *
 * Injected by ContentManager after the main page controller whenever
 * page.comments = 1. All comment template vars live in the 'comments_*'
 * namespace. Nested boxes are produced by a flat loop: each enriched row
 * carries close_divs_html (N closing </div> tags) so the template engine
 * never needs to recurse.
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
        private readonly Gate                  $gate,
        private readonly UserSession           $session,
        private readonly CsrfHandler           $csrf,
        private readonly PrgHandler            $prg,
        private readonly UrlGenerator          $urlGen,
        private readonly Translator            $t,
        private readonly AvatarService         $avatarService,
        private readonly CaptchaService        $captchaService,
    ) {
        parent::__construct($collector);
    }

    public function handle(): Result
    {
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $this->processSubmission($prgToken);
            $pageUrl = $this->urlGen->toPage(
                $this->t->t($this->page->urlId, fallback: $this->page->urlId)
            );
            Response::redirect($pageUrl)->send()->drainTo($this->collector);
            exit;
        }

        $this->renderComments();
        return $this->ok();
    }

    // =========================================================================

    private function processSubmission(string $prgToken): void
    {
        $posted     = $this->prg->pull($prgToken) ?? [];
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

        // Captcha verification for guest users
        if (!$this->session->isLoggedIn()) {
            $captchaId   = (string) ($posted['captcha_id']   ?? '');
            $captchaText = (string) ($posted['captcha_text'] ?? '');
            $captchaResult = $this->captchaService->verify($captchaId, $captchaText);
            if (!$captchaResult->isOk()) {
                $captchaResult->drainTo($this->collector);
                return;
            }
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

    // =========================================================================

    private function renderComments(): void
    {
        $pageNum    = max(1, (int) ($this->request->query()->get('cp') ?? 1));
        $descending = $this->request->query()->get('cd') !== '0';

        $commentsResult = $this->commentService->getCommentsForPage(
            $this->page->id, $pageNum, $descending
        );
        $commentsResult->drainTo($this->collector);

        $countResult = $this->commentService->countForPage($this->page->id);
        $countResult->drainTo($this->collector);

        $total     = $countResult->isOk() ? $countResult->unwrap() : 0;
        $perPage   = $this->commentService->commentsPerPage();
        $pageCount = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
        $flat      = $commentsResult->isOk() ? $commentsResult->unwrap() : [];

        $isAdmin        = $this->gate->can(Permission::ADMIN_COMMENTS);
        $allowReplies   = $this->commentService->allowReplies();
        $useIdenticons  = $this->avatarService->useIdenticons();
        $profilePageKey = $this->t->t('WORDING_PROFILE', fallback: 'WORDING_PROFILE');
        $profileBase    = $this->urlGen->toPage($profilePageKey);

        // ── Enrich each comment row ───────────────────────────────────────────
        //
        // AstrX Mustache engine context rule: {{#section}} sets $parent = $resolved.
        // A bool/int/string value clobbers the row context, making inner vars undefined.
        // Every conditional section that needs to read row fields must be [$enriched]
        // (truthy, count=1, engine sets $parent = $enriched[0]) or false (skipped).
        //
        // close_divs_html: outer comment divs are left open to nest children inside.
        // After the inner content div, {{&close_divs_html}} closes the correct number
        // of outer divs. Formula (current depth D, next depth N):
        //   N > D  → '' (child follows, outer div stays open)
        //   N == D → '</div>' (sibling)
        //   N < D  → '</div>' × (D−N+1) (close current + ancestors)
        //   no next → '</div>' × (D+1) (close all)
        $comments = [];
        foreach ($flat as $i => $row) {
            $isHidden = (bool) ($row['hidden'] ?? false);
            $replyTo  = isset($row['reply_to']) && (int) $row['reply_to'] > 0
                ? (int) $row['reply_to'] : null;

            if ($row['user_id'] !== null) {
                // Registered user
                $displayName = (string) ($row['user_display_name'] ?? $row['name'] ?? 'Anonymous');
                $avatarSrc   = $this->urlGen->toPage('avatar', ['uid' => (string) $row['user_id']]);
                $profileUrl  = $profileBase . '?uid=' . rawurlencode((string) $row['user_id']);
            } else {
                // Guest
                $displayName = ($row['name'] ?? '') !== '' ? (string) $row['name'] : 'Anonymous';
                $profileUrl  = '';
                if ($useIdenticons) {
                    // Seed = sha256(name + packed_ip) — deterministic per guest identity.
                    // Use raw name + ip bytes; ip is varbinary from DB (inet_pton output).
                    $seed      = hash('sha256', ($row['name'] ?? '') . ($row['ip'] ?? ''));
                    $avatarSrc = $this->urlGen->toPage('avatar', ['seed' => $seed]);
                } else {
                    $avatarSrc = '';
                }
            }

            $enriched = $row + [
                    'display_name' => $displayName,
                    'avatar_src'   => $avatarSrc,
                    'profile_url'  => $profileUrl,
                    'is_own'       => $this->session->isLoggedIn()
                                      && $row['user_id'] === $this->session->userId(),
                    'row_opacity'  => $isHidden ? 'opacity:0.5;' : '',
                ];

            // Section wrappers — [$enriched] preserves row context inside Mustache sections.
            // RULE: never nest a section that needs row context inside another section.
            //       Each conditional that reads row fields gets its own top-level key.
            $hasAvatar  = ($avatarSrc !== '');
            $hasProfile = ($profileUrl !== '');

            // Avatar + profile link (registered user with avatar)
            $enriched['avatar_profile_section'] = ($hasAvatar && $hasProfile)  ? [$enriched] : false;
            // Avatar without profile link (guest with identicon)
            $enriched['avatar_plain_section']   = ($hasAvatar && !$hasProfile) ? [$enriched] : false;
            // Name as a profile link
            $enriched['name_profile_section']   = $hasProfile  ? [$enriched] : false;
            // Name as plain text
            $enriched['name_plain_section']     = !$hasProfile ? [$enriched] : false;
            // Reply-to badge
            $enriched['reply_to_section']       = ($replyTo !== null) ? [$enriched] : false;
            // Reply button
            $enriched['reply_section']          = $allowReplies        ? [$enriched] : false;
            // Admin buttons (separate per action, never nested)
            $enriched['admin_hide_section']     = ($isAdmin && !$isHidden) ? [$enriched] : false;
            $enriched['admin_unhide_section']   = ($isAdmin && $isHidden)  ? [$enriched] : false;
            $enriched['admin_delete_section']   = $isAdmin                 ? [$enriched] : false;

            // Nesting: close_divs_html
            $d  = (int) ($row['depth'] ?? 0);
            $nd = isset($flat[$i + 1]) ? (int) ($flat[$i + 1]['depth'] ?? 0) : -1;
            if ($nd > $d)        { $close = 0; }
            elseif ($nd === $d)  { $close = 1; }
            elseif ($nd >= 0)    { $close = $d - $nd + 1; }
            else                 { $close = $d + 1; }
            $enriched['close_divs_html'] = str_repeat('</div>', $close);

            $comments[] = $enriched;
        }

        // Pagination
        $baseUrl = $this->urlGen->toPage(
            $this->t->t($this->page->urlId, fallback: $this->page->urlId)
        );
        $pages = [];
        for ($p = 1; $p <= $pageCount; $p++) {
            $pages[] = [
                'number'     => $p,
                'url'        => $baseUrl . '?cp=' . $p,
                'is_current' => $p === $pageNum,
            ];
        }

        $csrfToken      = $this->csrf->generate(self::FORM);
        $prgId          = $this->prg->createId($baseUrl);
        $replyToPreFill = (int) ($this->request->query()->get('reply_to') ?? 0);

        // Captcha for guest users
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

        $this->ctx->set('comments',               $comments);
        $this->ctx->set('comments_any',           $comments !== []);
        $this->ctx->set('comments_reply_to',      $replyToPreFill > 0 ? $replyToPreFill : '');
        $this->ctx->set('comments_count',         $total);
        $this->ctx->set('comments_pages',         $pages);
        $this->ctx->set('has_pagination',         $pageCount > 1);
        $this->ctx->set('comments_page_current',  $pageNum);
        $this->ctx->set('comments_allow_replies', $allowReplies);
        $this->ctx->set('comments_logged_in',     $this->session->isLoggedIn());
        $this->ctx->set('comments_csrf',          $csrfToken);
        $this->ctx->set('comments_prg_id',        $prgId);
        $this->ctx->set('show_captcha',           $showCaptcha);
        $this->ctx->set('captcha_id',             $captchaId);
        $this->ctx->set('captcha_image',          $captchaImage);
        $this->ctx->set('comments_heading',       $this->t->t('comment.heading'));
        $this->ctx->set('comments_none',          $this->t->t('comment.none'));
        $this->ctx->set('comments_submit_heading',$this->t->t('comment.submit_heading'));
        $this->ctx->set('comment_label_name',     $this->t->t('comment.label.name'));
        $this->ctx->set('comment_label_email',    $this->t->t('comment.label.email'));
        $this->ctx->set('comment_label_content',  $this->t->t('comment.label.content'));
        $this->ctx->set('comment_label_reply',    $this->t->t('comment.label.reply'));
        $this->ctx->set('comment_label_captcha',  $this->t->t('comment.label.captcha'));
        $this->ctx->set('comment_btn_submit',     $this->t->t('comment.btn.submit'));
        $this->ctx->set('comment_btn_reply',      $this->t->t('comment.btn.reply'));
        $this->ctx->set('comment_btn_cancel_reply',$this->t->t('comment.btn.cancel_reply'));
        $this->ctx->set('comment_btn_hide',       $this->t->t('comment.btn.hide'));
        $this->ctx->set('comment_btn_unhide',     $this->t->t('comment.btn.unhide'));
        $this->ctx->set('comment_btn_delete',     $this->t->t('comment.btn.delete'));
    }
}