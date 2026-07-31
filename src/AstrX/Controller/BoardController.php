<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\Http\UploadedFile;
use AstrX\I18n\Translator;
use AstrX\Imageboard\BanRepository;
use AstrX\Imageboard\BoardNav;
use AstrX\Imageboard\BoardRepository;
use AstrX\Imageboard\BoardView;
use AstrX\Imageboard\ImageboardConfig;
use AstrX\Imageboard\ImageService;
use AstrX\Imageboard\PostRepository;
use AstrX\Imageboard\PostService;
use AstrX\Imageboard\SubmittedPost;
use AstrX\Imageboard\ThreadRepository;
use AstrX\Captcha\CaptchaService;
use AstrX\Imageboard\Diagnostic\ImageboardPostDiagnostic;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\FlashBag;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserSession;
use function AstrX\Support\langDir;

/**
 * Imageboard dispatcher — one template=1 page (WORDING_BOARD, file_name 'board')
 * that renders three views chosen from the URL tail:
 *   /board/<slug>              → index (threads with preview replies)
 *   /board/<slug>/catalog      → catalog grid
 *   /board/<slug>/thread/<id>  → a single thread
 * plus PRG-backed posting (new thread on the index, replies in a thread). No
 * board slug → a board list. All views work with JavaScript disabled.
 */
final class BoardController extends AbstractController
{
    private const FORM = 'board';

    /** @var array<string,string> hex user_id → configured role colour, for the current render. */
    private array $roleColorByUid = [];

    /** Thread URL a clicked post No. quotes into (varies per thread on the index). */
    private string $quoteBase = '';

    /**
     * Reply backlinks for the current render: post No. → the list of Nos that
     * quoted it. Computed server-side from the >> graph (thread view only).
     *
     * @var array<int,list<int>>
     */
    private array $backlinksByNo = [];

    public function __construct(
        \AstrX\Result\DiagnosticsCollector      $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly CurrentUrl             $currentUrl,
        private readonly BoardRepository        $boards,
        private readonly ThreadRepository       $threads,
        private readonly PostRepository         $posts,
        private readonly ImageService           $images,
        private readonly PostService            $postService,
        private readonly BoardView              $view,
        private readonly ImageboardConfig       $config,
        private readonly CaptchaService         $captchaService,
        private readonly Gate                   $gate,
        private readonly \AstrX\Csrf\CsrfHandler $csrf,
        private readonly PrgHandler             $prg,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
        private readonly UserSession            $session,
        private readonly BoardNav               $nav,
        private readonly BanRepository          $bans,
        private readonly FlashBag               $flash,
    ) {
        parent::__construct($collector);
    }

    /**
     * A logged-in poster the operator has allowed to post under their account.
     * Such posters skip the captcha and, unless they opt into anonymity, post
     * with their account identity attached.
     */
    private function authenticatedPoster(): bool
    {
        return $this->config->allowAuthenticatedPosts() && $this->session->isLoggedIn();
    }

    /**
     * Resolve the role colour for every authenticated poster on the page in one
     * query, keyed by hex user_id. Roles are matched by NAME against the
     * admin-configured colour map, so a role added later is coloured just by
     * adding an entry — unknown roles fall back to the theme's default name colour.
     *
     * @param list<string> $userIds hex user_ids gathered from the rendered posts
     */
    private function buildRoleColors(array $userIds): void
    {
        $this->roleColorByUid = [];
        $userIds = array_values(array_filter($userIds, static fn (string $u): bool => $u !== ''));
        if ($userIds === []) {
            return;
        }
        $tR    = $this->posts->typesByUserIds($userIds);
        $types = $tR->isOk() ? $tR->unwrap() : [];
        foreach ($types as $hex => $type) {
            $role  = (\AstrX\User\UserGroup::tryFrom($type) ?? \AstrX\User\UserGroup::USER)->name;
            $color = $this->config->roleColor($role);
            if ($color !== '') {
                $this->roleColorByUid[$hex] = $color;
            }
        }
    }

    /** The imageboard-only "styles" (classic themes), applied on top of the site theme. */
    private const STYLES = ['yotsuba', 'yotsuba-b', 'futaba', 'burichan', 'tomorrow', 'photon'];

    /**
     * Per-reader imageboard style (persisted in the session, no JS). A ?style=<name>
     * query — from the Style selector — updates it; 'default' clears it. Sets the
     * `board_style` class token and the selector list for the template.
     */
    private function setupStyle(): void
    {
        $q = $this->request->query()->get('style');
        if (is_string($q)) {
            if ($q === 'default') {
                unset($_SESSION['board_style']);
            } elseif (in_array($q, self::STYLES, true)) {
                $_SESSION['board_style'] = $q;
            }
        }
        $curRaw = $_SESSION['board_style'] ?? '';
        $cur    = is_string($curRaw) ? $curRaw : '';
        $this->ctx->set('board_style', $cur);

        // Reload the CURRENT board view when switching (path without query),
        // falling back to the board base if the server did not expose the URI.
        $uriRaw = $this->request->server()->get('REQUEST_URI');
        $uri    = is_scalar($uriRaw) ? (string) $uriRaw : '';
        $path   = strtok($uri, '?');
        if ($path === false) {
            $path = $this->boardBase();
        }

        $styles = [[
            'name'  => 'default',
            'label' => $this->t->t('board.style_default'),
            'url'   => $path . '?style=default',
            'cur'   => $cur === '',
        ]];
        foreach (self::STYLES as $s) {
            $styles[] = [
                'name'  => $s,
                'label' => $this->t->t('board.style_' . str_replace('-', '_', $s)),
                'url'   => $path . '?style=' . $s,
                'cur'   => $cur === $s,
            ];
        }
        $this->ctx->set('board_styles', $styles);
        $this->ctx->set('has_styles',   true);
        $this->ctx->set('lbl_style',    $this->t->t('board.style'));
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Imageboard');

        if ($this->gate->cannot(Permission::BOARD_VIEW)) {
            http_response_code(404);
            exit;
        }

        $this->setupStyle();

        $slug  = self::str($this->currentUrl->tailSegment(0));
        $bR    = $this->boards->bySlug($slug);
        $board = $bR->isOk() ? $bR->unwrap() : null;
        // Board navbars join the site header nav stack (partials/board_nav.html);
        // this flag switches them on for imageboard pages only.
        $this->ctx->set('board_nav_show', true);
        if (!is_array($board)) {
            // Imageboard-wide navbar (Boards home + Overboard + every board).
            $this->ctx->set('board_top_nav', $this->nav->topNav('home'));
            return $this->renderBoardList();
        }
        $this->ctx->set('board_top_nav', $this->nav->topNav(self::mStr($board, 'slug')));

        // PRG replay of a posted form.
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $redirect = $this->processSubmission($prgToken, $board);
            Response::redirect($redirect)->send()->drainTo($this->collector);
            exit;
        }

        $action = self::str($this->currentUrl->tailSegment(1));
        if ($action === 'catalog') {
            return $this->renderCatalog($board);
        }
        if ($action === 'thread') {
            return $this->renderThread($board, self::int($this->currentUrl->tailSegment(2)));
        }
        return $this->renderIndex($board);
    }

    // ── Views ────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function renderIndex(array $board): Result
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $this->setHeader($board, 'index');
        $this->setPostForm($board, $this->indexUrl($slug), false);
        $this->ctx->set('is_index', true);

        $tr        = $this->threads->page($bid, 0, max(1, $this->config->threadsPerPage()));
        $threadRows = $tr->isOk() ? $tr->unwrap() : [];

        $tids = [];
        foreach ($threadRows as $row) {
            $tids[] = self::mInt($row, 'id');
        }
        $opR = $this->posts->opsForThreads($tids);
        $ops = $opR->isOk() ? $opR->unwrap() : [];

        $previewByThread = [];
        $allPostIds      = [];
        foreach ($ops as $op) {
            $allPostIds[] = self::mInt($op, 'id');
        }
        foreach ($threadRows as $row) {
            $tid = self::mInt($row, 'id');
            $pr  = $this->posts->previewReplies($tid, max(0, $this->config->previewReplies()));
            $rep = $pr->isOk() ? $pr->unwrap() : [];
            $previewByThread[$tid] = $rep;
            foreach ($rep as $p) {
                $allPostIds[] = self::mInt($p, 'id');
            }
        }
        $imagesByPost = $this->images->forPosts($allPostIds);

        // Resolve role colours for every authenticated poster shown on the index.
        $uids = [];
        foreach ($ops as $op) { $uids[] = self::mStr($op, 'user_id'); }
        foreach ($previewByThread as $reps) {
            foreach ($reps as $p) { $uids[] = self::mStr($p, 'user_id'); }
        }
        $this->buildRoleColors($uids);

        $threads = [];
        foreach ($threadRows as $row) {
            $tid = self::mInt($row, 'id');
            $op  = $ops[$tid] ?? null;
            if (!is_array($op)) {
                continue;
            }
            // Clicking a post No. in this thread's preview quotes into this thread.
            $this->quoteBase = $this->threadUrl($slug, $tid);
            $replies = [];
            foreach ($previewByThread[$tid] ?? [] as $p) {
                $replies[] = $this->postCtx($p, $imagesByPost);
            }
            $replyCount = self::mInt($row, 'reply_count');
            $omitted    = max(0, $replyCount - count($replies));
            $opCtx               = $this->postCtx($op, $imagesByPost);
            $opCtx['subject']    = self::mStr($row, 'subject');
            $opCtx['has_subject'] = $opCtx['subject'] !== '';
            $threads[] = [
                'thread_url'  => $this->threadUrl($slug, $tid),
                'op'          => $opCtx,
                'replies'     => $replies,
                'reply_count' => $replyCount,
                'image_count' => self::mInt($row, 'image_count'),
                'omitted'     => $omitted,
                'has_omitted' => $omitted > 0,
                'sticky'      => self::mBool($row, 'sticky'),
                'locked'      => self::mBool($row, 'locked'),
            ];
        }
        $this->ctx->set('threads_html', $this->view->index($threads));
        $this->ctx->set('has_threads', $threads !== []);
        $this->setLabels();
        return $this->ok();
    }

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function renderCatalog(array $board): Result
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $this->setHeader($board, 'catalog');
        $this->ctx->set('is_catalog', true);

        $cr   = $this->threads->catalog($bid);
        $rows = $cr->isOk() ? $cr->unwrap() : [];
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
            $tid     = self::mInt($row, 'id');
            $op      = $ops[$tid] ?? null;
            $thumb   = '';
            $excerpt = '';
            if (is_array($op)) {
                $imgs = $imagesByPost[self::mInt($op, 'id')] ?? [];
                // Use the first non-video attachment as the catalog thumbnail
                // (videos have no server-side thumbnail).
                foreach ($imgs as $im) {
                    if (!str_starts_with(self::mStr($im, 'mime'), 'video/')) {
                        $thumb = $this->fileUrl(self::mStr($im, 'token'), true);
                        break;
                    }
                }
                $excerpt = mb_strimwidth(strip_tags(self::mStr($op, 'body_html')), 0, 140, '…');
            }
            $cells[] = [
                'thread_url'  => $this->threadUrl($slug, $tid),
                'subject'     => self::mStr($row, 'subject'),
                'reply_count' => self::mInt($row, 'reply_count'),
                'image_count' => self::mInt($row, 'image_count'),
                'thumb_url'   => $thumb,
                'has_thumb'   => $thumb !== '',
                'excerpt'     => $excerpt,
            ];
        }
        $this->ctx->set('catalog_html', $this->view->catalog($cells));
        $this->ctx->set('has_catalog', $cells !== []);
        $this->setLabels();
        return $this->ok();
    }

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function renderThread(array $board, int $tid): Result
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $thR    = $this->threads->byId($tid);
        $thread = $thR->isOk() ? $thR->unwrap() : null;
        if (!is_array($thread) || self::mInt($thread, 'board_id') !== $bid) {
            return $this->renderIndex($board);
        }
        $this->setHeader($board, 'thread');
        $this->setPostForm($board, $this->threadUrl($slug, $tid), true);
        $this->ctx->set('is_thread', true);
        $this->ctx->set('thread_id', $tid);
        $this->ctx->set('thread_subject', self::mStr($thread, 'subject'));
        $this->ctx->set('thread_locked', self::mBool($thread, 'locked'));

        $pr       = $this->posts->forThread($tid);
        $postRows = $pr->isOk() ? $pr->unwrap() : [];
        $pids     = [];
        $uids     = [];
        foreach ($postRows as $p) {
            $pids[] = self::mInt($p, 'id');
            $uids[] = self::mStr($p, 'user_id');
        }
        $imagesByPost = $this->images->forPosts($pids);
        $this->buildRoleColors($uids);
        $this->buildBacklinks($postRows);
        // Clicking a post No. in this thread quotes into this same thread.
        $this->quoteBase = $this->threadUrl($slug, $tid);

        $posts = [];
        foreach ($postRows as $p) {
            $c = $this->postCtx($p, $imagesByPost);
            if (self::mBool($p, 'is_op')) {
                $c['subject']     = self::mStr($thread, 'subject');
                $c['has_subject'] = $c['subject'] !== '';
            }
            $posts[] = $c;
        }
        $this->ctx->set('posts_html', $this->view->thread($posts));
        $this->setLabels();
        return $this->ok();
    }

    /** @return Result<mixed> */
    private function renderBoardList(): Result
    {
        $this->ctx->set('is_board_list', true);
        $lr   = $this->boards->listActive();
        $rows = $lr->isOk() ? $lr->unwrap() : [];
        $list = [];
        foreach ($rows as $row) {
            $slug   = self::mStr($row, 'slug');
            $list[] = [
                'slug'     => $slug,
                'title'    => self::mStr($row, 'title'),
                'subtitle' => self::mStr($row, 'subtitle'),
                'url'      => $this->indexUrl($slug),
            ];
        }
        $this->ctx->set('boards', $list);
        $this->ctx->set('has_boards', $list !== []);
        $this->setLabels();
        return $this->ok();
    }

    // ── POST (PRG) ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $board
     * @return string redirect URL
     */
    private function processSubmission(string $prgToken, array $board): string
    {
        $slug     = self::mStr($board, 'slug');
        $bid      = self::mInt($board, 'id');
        $posted   = $this->prg->pull($prgToken) ?? [];
        $threadId = self::mInt($posted, 'thread', 0);
        $backUrl  = $threadId > 0 ? $this->threadUrl($slug, $threadId) : $this->indexUrl($slug);

        $csrf = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf', ''));
        if (!$csrf->isOk()) {
            $csrf->drainTo($this->collector);
            return $backUrl;
        }
        if ($this->gate->cannot(Permission::BOARD_POST)) {
            return $this->indexUrl($slug);
        }

        // Anti-automation: captcha-gate anonymous posts when configured. An
        // authenticated poster (allowed by config + logged in) is a known human
        // and is exempt — the captcha only guards guest posts.
        if ($this->config->guestCaptcha() && !$this->authenticatedPoster()) {
            $captcha = $this->captchaService->verify(
                self::mStr($posted, 'captcha_id', ''),
                self::mStr($posted, 'captcha_text', ''),
            );
            if (!$captcha->isOk()) {
                $captcha->drainTo($this->collector);
                return $backUrl;
            }
        }

        $ip        = $this->packedIp();
        $posterKey = $ip !== null ? hash('sha256', $ip) : '';

        // Ban enforcement: reject a banned poster before any post is created.
        // The check uses the REAL logged-in account (even when the poster ticks
        // "anonymous") and the REAL request IP (even when store_poster_ip is off),
        // not the possibly-null persisted identity — otherwise a ban could be
        // dodged by posting anonymously or on the onion default. A global or
        // this-board ban, on the account OR the IP/CIDR, blocks the post.
        $banUid = $this->session->isLoggedIn() ? $this->session->userId() : null;
        // Don't IP-match a loopback/shared REMOTE_ADDR (mirrors the cooldown guard):
        // on a Tor/proxied deployment every visitor shares it, so IP-matching a ban
        // would otherwise block ALL posting site-wide once one poster's IP is banned.
        $banIp  = ($ip !== null && !self::isLoopback($ip)) ? $ip : null;
        $banR   = $this->bans->findActiveFor($banUid, $banIp, $bid);
        if ($banR->isOk() && $banR->unwrap() !== null) {
            $this->flash->set('error', $this->t->t('board.error.banned'));
            return $backUrl;
        }

        // Flood control: enforce the board's per-poster cooldown.
        $cooldown = self::mInt($board, 'cooldown_secs');
        if ($cooldown > 0 && $this->onCooldown($bid, $posterKey, $ip, $cooldown)) {
            $this->emit(new ImageboardPostDiagnostic('astrx.imageboard/cooldown', DiagnosticLevel::NOTICE));
            return $backUrl;
        }

        // Collect every file input (image, image2, …) up to the per-post limit.
        $images = $this->collectFiles();

        // ── Identity ─────────────────────────────────────────────────────────
        // A logged-in poster defaults to posting under their account. They can
        // opt out per-post with the "anonymous" checkbox to appear as an ordinary
        // visitor (no account link, free-text name). forced_anon boards always
        // strip identity, so no account is attached there.
        $forcedAnon = self::mBool($board, 'forced_anon');
        $postAnon   = self::mBool($posted, 'anon');
        $name       = self::mStr($posted, 'name');
        $hexUserId  = null;
        if ($this->authenticatedPoster() && !$forcedAnon && !$postAnon) {
            $hexUserId = $this->session->userId();
            $accountName = $this->session->displayName();
            if ($accountName === '') {
                $accountName = $this->session->username();
            }
            $name = $accountName;
        }

        // Capcode: a logged-in staff member may tick "post with capcode" to show
        // their role badge (## Admin / ## Mod). Only honoured for real staff and
        // never on a forced-anon board. The token is validated in PostService.
        $capcode = '';
        if (self::mBool($posted, 'capcode') && !$forcedAnon && $this->session->isLoggedIn()) {
            if ($this->session->isAdmin())   { $capcode = 'admin'; }
            elseif ($this->session->isMod()) { $capcode = 'mod'; }
        }

        // Privacy: on the default (onion) deployment we compute the IP/poster-key
        // for this request's cooldown but do NOT persist them. Only store at rest
        // when the operator explicitly opts in (clearnet with a stated purpose).
        $store = $this->config->storePosterIp();
        $submission = new SubmittedPost(
            name:           $name,
            subject:        self::mStr($posted, 'subject'),
            body:           self::mStr($posted, 'comment'),
            sage:           self::mBool($posted, 'sage'),
            deletePassword: self::mStr($posted, 'password'),
            images:         $images,
            packedIp:       $store ? $ip : null,
            hexUserId:      $hexUserId,
            posterKey:      $store ? $posterKey : '',
            spoiler:        self::mBool($posted, 'spoiler'),
            flagCode:       self::mStr($posted, 'flag'),
            capcode:        $capcode,
            identityToken:  $this->identityToken(),
        );

        if ($threadId > 0) {
            $r = $this->postService->reply($threadId, $submission);
            $r->drainTo($this->collector);
            if ($r->isOk()) {
                $this->markPosted($bid);
            }
            return $this->threadUrl($slug, $threadId);
        }
        $r = $this->postService->createThread($bid, $submission);
        $r->drainTo($this->collector);
        if ($r->isOk()) {
            $this->markPosted($bid);
            return $this->threadUrl($slug, $r->unwrap());
        }
        return $this->indexUrl($slug);
    }

    // ── Anti-automation ───────────────────────────────────────────────────────

    /** Generate + expose a captcha for the post form when guest captcha is on. */
    private function setCaptcha(): void
    {
        // Authenticated posters (config-allowed + logged in) are exempt, so the
        // form shows no captcha for them at all.
        $show = $this->config->guestCaptcha() && !$this->authenticatedPoster();
        $this->ctx->set('show_captcha',      $show);
        $this->ctx->set('has_captcha_frame', false);
        $cid = ''; $cimg = '';
        if ($show) {
            $gen = $this->captchaService->generate();
            $gen->drainTo($this->collector);
            if ($gen->isOk()) {
                $u    = $gen->unwrap();
                $cid  = $u['id'];
                $cimg = $u['image_b64'];
            }
        }
        $this->ctx->set('captcha_id',    $cid);
        $this->ctx->set('captcha_image', $cimg);
        $this->ctx->set('captcha_label', $this->t->t('board.captcha'));
    }

    /**
     * True while this poster is still inside the board's post cooldown.
     *
     * The per-session (per-browser) timer is the primary key on a pure onion,
     * where every poster shares one REMOTE_ADDR. It is best-effort — a client can
     * drop its cookie — so the single-use captcha is the real anti-automation
     * gate; this cooldown just damps casual/accidental repeat posts.
     *
     * The per-poster-key (per-IP) timer compares against STORED poster_keys, so
     * it only functions when store_poster_ip is on (a clearnet deployment). On
     * the onion default nothing is stored and the key would be a shared constant
     * anyway, so it is skipped.
     */
    private function onCooldown(int $boardId, string $posterKey, ?string $ip, int $cooldown): bool
    {
        // Per-board session timer: a cooldown on /a/ must not block /b/. Keyed by
        // board id (an older single-scalar value is simply ignored on read).
        $store  = $_SESSION['board_last_post'] ?? null;
        $last   = (is_array($store) && isset($store[$boardId])) ? $store[$boardId] : null;
        $lastTs = is_int($last) ? $last : 0;
        if ($lastTs > 0 && (time() - $lastTs) < $cooldown) {
            return true;
        }
        if ($this->config->storePosterIp() && $ip !== null && $posterKey !== '' && !self::isLoopback($ip)) {
            $r     = $this->posts->lastPostAtByPosterKey($boardId, $posterKey);
            $keyTs = $r->isOk() ? $r->unwrap() : 0;
            if ($keyTs > 0 && (time() - $keyTs) < $cooldown) {
                return true;
            }
        }
        return false;
    }

    private function markPosted(int $boardId): void
    {
        // Per-board timestamps so each board's cooldown is independent.
        $store = $_SESSION['board_last_post'] ?? null;
        if (!is_array($store)) {
            $store = [];
        }
        $store[$boardId] = time();
        $_SESSION['board_last_post'] = $store;
    }

    /** True if a packed (inet_pton) address is loopback (127.0.0.0/8, ::1, or ::ffff:127/8). */
    private static function isLoopback(string $packedIp): bool
    {
        if (strlen($packedIp) === 4) {
            return $packedIp[0] === "\x7f";          // 127.0.0.0/8
        }
        if (strlen($packedIp) === 16) {
            if ($packedIp === inet_pton('::1')) {
                return true;                         // ::1
            }
            // IPv4-mapped IPv6 loopback (::ffff:127.0.0.0/8)
            return str_starts_with($packedIp, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")
                && $packedIp[12] === "\x7f";
        }
        return false;
    }

    // ── Context builders ──────────────────────────────────────────────────────

    /** @param array<string,mixed> $board */
    private function setHeader(array $board, string $activeView = ''): void
    {
        $slug = self::mStr($board, 'slug');
        $this->ctx->set('board_slug',     $slug);
        $this->ctx->set('board_title',    self::mStr($board, 'title'));
        $this->ctx->set('board_subtitle', self::mStr($board, 'subtitle'));
        $this->ctx->set('board_desc',     self::mStr($board, 'description'));
        $this->ctx->set('board_nsfw',     self::mBool($board, 'nsfw'));
        $this->ctx->set('index_url',      $this->indexUrl($slug));
        $this->ctx->set('catalog_url',    $this->catalogUrl($slug));

        // Moderation link — surfaced to staff who can moderate this board so the
        // BoardModController surface is reachable from the board itself.
        $canMod = $this->gate->can(Permission::BOARD_MODERATE);
        $modUrl = $canMod
            ? $this->urlGen->toPage($this->t->t('WORDING_BOARD_MOD')) . '?board=' . rawurlencode($slug)
            : '';
        $this->ctx->set('can_moderate', $canMod);
        $this->ctx->set('mod_url', $modUrl);

        // Per-board banner (a site-relative image path only, for Tor-safety) and
        // a rules/info blurb shown in a native <details> disclosure (no JS).
        $banner   = self::mStr($board, 'banner');
        $hasBanner = str_starts_with($banner, '/');
        $this->ctx->set('board_banner', $hasBanner ? $banner : '');
        $this->ctx->set('has_banner',   $hasBanner);
        $rules = self::mStr($board, 'rules');
        $this->ctx->set('board_rules_html',
            $rules !== '' ? nl2br(htmlspecialchars($rules, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5), false) : '');
        $this->ctx->set('has_rules', $rules !== '');
        $this->ctx->set('lbl_rules', $this->t->t('board.rules'));

        // Discovery links (Atom feed for this board, cross-board overboard,
        // search). Built from the seeded page wordings; all no-JS.
        $feedUrl   = $this->urlGen->toPage($this->t->t('WORDING_BOARD_FEED')) . '?board=' . rawurlencode($slug);
        $searchUrl = $this->urlGen->toPage($this->t->t('WORDING_BOARD_SEARCH')) . '?board=' . rawurlencode($slug);
        $this->ctx->set('feed_url',      $feedUrl);
        $this->ctx->set('overboard_url', $this->urlGen->toPage($this->t->t('WORDING_BOARD_OVERBOARD')));
        $this->ctx->set('search_url',    $searchUrl);
        $this->ctx->set('lbl_feed',      $this->t->t('board.feed'));
        $this->ctx->set('lbl_overboard', $this->t->t('board.overboard_heading'));
        $this->ctx->set('lbl_search',    $this->t->t('board.search_heading'));

        // Per-board action nav (Index / Catalog / Search / Feed / Manage),
        // rendered in the site navbar style. The active view is highlighted.
        $localNav = [
            ['url' => $this->indexUrl($slug),   'name' => $this->t->t('board.index'),   'highlight' => $activeView === 'index'],
            ['url' => $this->catalogUrl($slug), 'name' => $this->t->t('board.catalog'), 'highlight' => $activeView === 'catalog'],
            ['url' => $searchUrl,               'name' => $this->t->t('board.search_heading'), 'highlight' => false],
            ['url' => $feedUrl,                 'name' => $this->t->t('board.feed'),    'highlight' => false],
        ];
        if ($canMod) {
            $localNav[] = ['url' => $modUrl, 'name' => $this->t->t('board.manage'), 'highlight' => false];
        }
        $this->ctx->set('board_local_nav',     $localNav);
        $this->ctx->set('has_board_local_nav', true);
    }

    /** @param array<string,mixed> $board */
    private function setPostForm(array $board, string $actionUrl, bool $isReply): void
    {
        $this->ctx->set('can_post',    $this->gate->can(Permission::BOARD_POST));
        $this->ctx->set('form_action', $actionUrl);
        $this->ctx->set('is_reply',    $isReply);
        $this->ctx->set('prg_id',      $this->prg->createId($actionUrl));
        $this->ctx->set('csrf_token',  $this->csrf->generate(self::FORM));
        $this->ctx->set('max_len',     self::mInt($board, 'max_post_len'));

        // Quote pre-fill: arriving via a post-No. link (?quote=N) seeds the reply
        // box with ">>N". The template renders it into the textarea (Mustache-
        // escaped), so the browser submits the literal ">>N".
        $quoteRaw = $this->request->query()->get('quote');
        $quoteNo  = is_numeric($quoteRaw) ? (int) $quoteRaw : 0;
        $this->ctx->set('comment_prefill', $quoteNo > 0 ? '>>' . $quoteNo . "\n" : '');

        // Authenticated posting UI: when a logged-in user is allowed to post
        // under their account (and the board isn't forced-anon), show the
        // "posting as <name>" notice plus the opt-out anonymous checkbox. Guests
        // and forced-anon boards fall back to the plain name field.
        $forcedAnon = self::mBool($board, 'forced_anon');
        $postAsUser = $this->authenticatedPoster() && !$forcedAnon;
        $this->ctx->set('post_as_user', $postAsUser);
        if ($postAsUser) {
            $accountName = $this->session->displayName();
            if ($accountName === '') {
                $accountName = $this->session->username();
            }
            $this->ctx->set('user_display_name', $accountName);
        }

        // Multiple attachments: expose the extra file slots (image2, image3, …).
        $max        = max(1, $this->config->maxFilesPerPost());
        $extraFiles = [];
        for ($i = 2; $i <= $max; $i++) {
            $extraFiles[] = ['name' => 'image' . $i];
        }
        $this->ctx->set('extra_files',     $extraFiles);
        $this->ctx->set('has_extra_files', $extraFiles !== []);

        // accept="" hint listing image (and, when enabled, video) extensions.
        $accept = [];
        foreach ($this->config->uploadTypes() as $t) { $accept[] = '.' . $t; }
        if ($this->config->videoEnabled()) {
            foreach ($this->config->videoTypes() as $t) { $accept[] = '.' . $t; }
        }
        $this->ctx->set('file_accept', implode(',', $accept));

        // Self-selectable flags: only when the board runs flags in 'user' mode
        // and the operator configured a flag set. No geo-IP — the poster picks.
        $flags = [];
        if (self::mStr($board, 'flags_mode') === 'user') {
            foreach ($this->config->boardFlags() as $code => $label) {
                $flags[] = ['code' => $code, 'name' => $label];
            }
        }
        $this->ctx->set('flag_options',  $flags);
        $this->ctx->set('flags_enabled', $flags !== []);

        // Capcode: a logged-in staff member may post with their role badge.
        $canCapcode = !$forcedAnon && $this->session->isLoggedIn()
            && ($this->session->isAdmin() || $this->session->isMod());
        $this->ctx->set('can_capcode', $canCapcode);

        $this->setCaptcha();
    }

    /**
     * Build the template vars for one post row.
     *
     * @param array<string,mixed> $post
     * @param array<int,list<array<string,mixed>>> $imagesByPost
     * @return array<string,mixed>
     */
    private function postCtx(array $post, array $imagesByPost): array
    {
        $pid = self::mInt($post, 'id');
        $no  = self::mInt($post, 'no');
        $name = self::mStr($post, 'name');

        // Posts made under an account carry a hex user_id (LOWER(HEX(user_id))
        // from PostRepository). Link such a post's name to the poster's profile;
        // anonymous/guest posts have an empty user_id and render a plain name.
        $uid        = self::mStr($post, 'user_id');
        $profileUrl = $uid !== ''
            ? $this->urlGen->toPage($this->t->t('WORDING_PROFILE', fallback: 'WORDING_PROFILE'))
              . '?uid=' . rawurlencode($uid)
            : '';

        $revEnabled = $this->config->reverseImageSearch();
        $imgs = [];
        foreach ($imagesByPost[$pid] ?? [] as $im) {
            $token   = self::mStr($im, 'token');
            $mime    = self::mStr($im, 'mime');
            $isVideo = str_starts_with($mime, 'video/');
            $full    = $this->fileUrl($token, false);
            $imgs[] = [
                'full_url'  => $full,
                'thumb_url' => $this->fileUrl($token, true),
                'w'         => self::mInt($im, 'width'),
                'h'         => self::mInt($im, 'height'),
                'tw'        => self::mInt($im, 'thumb_w'),
                'th'        => self::mInt($im, 'thumb_h'),
                'spoiler'   => self::mBool($im, 'spoiler'),
                'orig'      => self::mStr($im, 'orig_name'),
                'mime'      => $mime,
                'is_video'  => $isVideo,
                'rev'       => ($revEnabled && !$isVideo) ? $this->reverseLinks($full) : [],
                'has_rev'   => $revEnabled && !$isVideo,
            ];
        }

        // Identity badges (empty unless the board/poster set them).
        $capToken  = self::mStr($post, 'capcode');
        $capLabel  = $capToken !== '' ? $this->capcodeLabel($capToken) : '';
        $flagCode  = self::mStr($post, 'flag_code');
        $flagLabel = $flagCode !== '' ? ($this->config->boardFlags()[$flagCode] ?? '') : '';

        // Reply backlinks for this post (computed on the thread view only).
        $backlinks = [];
        foreach ($this->backlinksByNo[$no] ?? [] as $bn) {
            $backlinks[] = ['no' => $bn, 'url' => '#p' . $bn];
        }

        return [
            'post_id'     => 'p' . $no,
            'no'          => $no,
            'name'        => $name !== '' ? $name : $this->t->t('board.anonymous'),
            'profile_url' => $profileUrl,
            'is_registered' => $uid !== '',
            // Role colour for this poster (empty for anon/unmapped roles).
            'name_color'  => $uid !== '' ? ($this->roleColorByUid[$uid] ?? '') : '',
            // Link that quotes this post into the reply box (>>no).
            'quote_url'   => $this->quoteBase !== '' ? $this->quoteBase . '?quote=' . $no . '#board-post-form' : '',
            'subject'     => self::mStr($post, 'subject'),
            'has_subject' => self::mStr($post, 'subject') !== '',
            'body_html'   => self::mStr($post, 'body_html'),
            'time'        => date('Y-m-d H:i', self::mInt($post, 'created_ts')),
            'is_op'       => self::mBool($post, 'is_op'),
            'images'      => $imgs,
            'has_images'  => $imgs !== [],
            // Identity: tripcode (!token), staff capcode (## label), per-thread
            // poster ID, and self-selected flag — each empty unless present.
            'tripcode'     => self::mStr($post, 'tripcode'),
            'has_tripcode' => self::mStr($post, 'tripcode') !== '',
            'capcode'      => $capLabel,
            'has_capcode'  => $capLabel !== '',
            'poster_id'    => self::mStr($post, 'poster_id'),
            'has_poster_id' => self::mStr($post, 'poster_id') !== '',
            'flag_label'   => $flagLabel,
            'has_flag'     => $flagLabel !== '',
            // "replies: >>x >>y" backlinks.
            'backlinks'     => $backlinks,
            'has_backlinks' => $backlinks !== [],
        ];
    }

    /** Human label for a staff capcode token (## Admin / ## Mod). */
    private function capcodeLabel(string $token): string
    {
        return match ($token) {
            'admin' => $this->t->t('board.capcode_admin'),
            'mod'   => $this->t->t('board.capcode_mod'),
            default => '',
        };
    }

    /**
     * Reverse-image-search links for a full-image URL. Config-gated (OFF by
     * default): they hand the image URL to a third party, and an onion URL is
     * unreachable to them anyway — only meaningful on a clearnet deployment.
     *
     * @return list<array{label:string,url:string}>
     */
    private function reverseLinks(string $fileUrl): array
    {
        $abs = $this->absoluteUrl($fileUrl);
        if ($abs === '') {
            return [];
        }
        $enc = rawurlencode($abs);
        return [
            ['label' => 'iqdb',     'url' => 'https://iqdb.org/?url=' . $enc],
            ['label' => 'SauceNAO', 'url' => 'https://saucenao.com/search.php?url=' . $enc],
        ];
    }

    /** Best-effort absolute URL from a site-relative path using the request host. */
    private function absoluteUrl(string $path): string
    {
        $hostRaw = $this->request->server()->get('HTTP_HOST');
        $host    = is_scalar($hostRaw) ? (string) $hostRaw : '';
        if ($host === '' || !str_starts_with($path, '/')) {
            return '';
        }
        $httpsRaw = $this->request->server()->get('HTTPS');
        $https    = is_scalar($httpsRaw) ? (string) $httpsRaw : '';
        $scheme   = ($https !== '' && $https !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . $path;
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_boards_heading'  => 'board.boards_heading',
            'lbl_new_thread'      => 'board.new_thread',
            'lbl_reply_title'     => 'board.reply_title',
            'lbl_name'            => 'board.name',
            'lbl_posting_as'      => 'board.posting_as',
            'lbl_post_anon'       => 'board.post_anon',
            'lbl_anon_name'       => 'board.anon_name',
            'lbl_anon_name_hint'  => 'board.anon_name_hint',
            'lbl_subject'         => 'board.subject',
            'lbl_comment'         => 'board.comment',
            'lbl_image'           => 'board.image',
            'lbl_spoiler_image'   => 'board.spoiler_image',
            'lbl_post'            => 'board.post',
            'lbl_sage'            => 'board.sage',
            'lbl_password'        => 'board.password',
            'lbl_password_hint'   => 'board.password_hint',
            'lbl_catalog'         => 'board.catalog',
            'lbl_index'           => 'board.index',
            'lbl_return'          => 'board.return',
            'lbl_reply'           => 'board.reply',
            'lbl_replies_omitted' => 'board.replies_omitted',
            'lbl_no_threads'      => 'board.no_threads',
            'lbl_view_full'       => 'board.view_full',
            'lbl_spoiler'         => 'board.spoiler',
            'lbl_locked'          => 'board.locked',
            'lbl_sticky'          => 'board.sticky',
            'lbl_nsfw'            => 'board.nsfw',
            'lbl_replies'         => 'board.replies',
            'lbl_images'          => 'board.images',
            'lbl_formatting_hint' => 'board.formatting_hint',
            'lbl_flag'              => 'board.flag',
            'lbl_no_flag'           => 'board.no_flag',
            'lbl_post_with_capcode' => 'board.post_with_capcode',
            'lbl_add_file'          => 'board.add_file',
            'lbl_manage'            => 'board.manage',
            'lbl_replies_to'        => 'board.replies_to',
            'lbl_poster_id'         => 'board.poster_id',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }

    // ── URL + IP helpers ──────────────────────────────────────────────────────

    private function boardBase(): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_BOARD'));
    }

    private function indexUrl(string $slug): string
    {
        return $this->boardBase() . '/' . rawurlencode($slug);
    }

    private function catalogUrl(string $slug): string
    {
        return $this->indexUrl($slug) . '/catalog';
    }

    private function threadUrl(string $slug, int $tid): string
    {
        return $this->indexUrl($slug) . '/thread/' . $tid;
    }

    private function fileUrl(string $token, bool $thumb): string
    {
        $base = $this->urlGen->toPage($this->t->t('WORDING_BOARD_FILE'));
        return $base . '?t=' . rawurlencode($token) . ($thumb ? '&thumb=1' : '');
    }

    /**
     * Collect the post's file inputs (image, image2, …) in order, keeping only
     * valid uploads, up to the configured per-post limit.
     *
     * @return list<UploadedFile>
     */
    private function collectFiles(): array
    {
        $files = [];
        $max   = max(1, $this->config->maxFilesPerPost());
        for ($i = 1; $i <= $max; $i++) {
            $key = $i === 1 ? 'image' : 'image' . $i;
            $up  = $this->request->files()->get($key);
            if ($up instanceof UploadedFile && $up->isValid()) {
                $files[] = $up;
            }
        }
        return $files;
    }

    /**
     * A per-browser identity token for per-thread poster IDs. Derived from the
     * PHP session id (stable per browser, rotates on logout) — never an IP, so
     * it works and stays private on a pure onion where every poster shares one
     * REMOTE_ADDR.
     */
    private function identityToken(): string
    {
        $sid = session_id();
        return is_string($sid) && $sid !== '' ? hash('sha256', $sid) : '';
    }

    /**
     * Build the reply-backlink graph for a set of rendered posts: for each post,
     * the list of later posts that quoted it (>>no). Read from the already-
     * rendered body_html, where PostRenderer tags each quote link with
     * data-no="<target>". Populates $this->backlinksByNo for postCtx().
     *
     * @param list<array<string,mixed>> $postRows
     */
    private function buildBacklinks(array $postRows): void
    {
        $this->backlinksByNo = [];
        foreach ($postRows as $row) {
            $fromNo = self::mInt($row, 'no');
            if ($fromNo <= 0) {
                continue;
            }
            if (preg_match_all('~data-no="(\d+)"~', self::mStr($row, 'body_html'), $m) === false) {
                continue;
            }
            $seen = [];
            foreach ($m[1] as $target) {
                $toNo = (int) $target;
                if ($toNo <= 0 || $toNo === $fromNo || isset($seen[$toNo])) {
                    continue;
                }
                $seen[$toNo] = true;
                $this->backlinksByNo[$toNo][] = $fromNo;
            }
        }
    }

    /** The poster's packed IP (inet_pton), or null when unavailable. */
    private function packedIp(): ?string
    {
        $ipRaw = $this->request->server()->get('REMOTE_ADDR');
        $ip    = is_scalar($ipRaw) ? (string) $ipRaw : '';
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        return inet_pton($ip) ?: null;
    }
}
