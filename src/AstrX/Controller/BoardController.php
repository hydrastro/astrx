<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\Http\UploadedFile;
use AstrX\I18n\Translator;
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

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Imageboard');

        if ($this->gate->cannot(Permission::BOARD_VIEW)) {
            http_response_code(404);
            exit;
        }

        $slug  = self::str($this->currentUrl->tailSegment(0));
        $bR    = $this->boards->bySlug($slug);
        $board = $bR->isOk() ? $bR->unwrap() : null;
        if (!is_array($board)) {
            return $this->renderBoardList();
        }

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
        $this->setHeader($board);
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
        $this->setHeader($board);
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
                if ($imgs !== []) {
                    $thumb = $this->fileUrl(self::mStr($imgs[0], 'token'), true);
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
        $this->setHeader($board);
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

        // Flood control: enforce the board's per-poster cooldown.
        $cooldown = self::mInt($board, 'cooldown_secs');
        if ($cooldown > 0 && $this->onCooldown($bid, $posterKey, $ip, $cooldown)) {
            $this->emit(new ImageboardPostDiagnostic('astrx.imageboard/cooldown', DiagnosticLevel::NOTICE));
            return $backUrl;
        }

        $image = null;
        $up    = $this->request->files()->get('image');
        if ($up instanceof UploadedFile && $up->isValid()) {
            $image = $up;
        }
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
            image:          $image,
            packedIp:       $store ? $ip : null,
            hexUserId:      $hexUserId,
            posterKey:      $store ? $posterKey : '',
            spoiler:        self::mBool($posted, 'spoiler'),
        );

        if ($threadId > 0) {
            $r = $this->postService->reply($threadId, $submission);
            $r->drainTo($this->collector);
            if ($r->isOk()) {
                $this->markPosted();
            }
            return $this->threadUrl($slug, $threadId);
        }
        $r = $this->postService->createThread($bid, $submission);
        $r->drainTo($this->collector);
        if ($r->isOk()) {
            $this->markPosted();
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
        $last   = $_SESSION['board_last_post'] ?? null;
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

    private function markPosted(): void
    {
        $_SESSION['board_last_post'] = time();
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
    private function setHeader(array $board): void
    {
        $slug = self::mStr($board, 'slug');
        $this->ctx->set('board_slug',     $slug);
        $this->ctx->set('board_title',    self::mStr($board, 'title'));
        $this->ctx->set('board_subtitle', self::mStr($board, 'subtitle'));
        $this->ctx->set('board_desc',     self::mStr($board, 'description'));
        $this->ctx->set('board_nsfw',     self::mBool($board, 'nsfw'));
        $this->ctx->set('index_url',      $this->indexUrl($slug));
        $this->ctx->set('catalog_url',    $this->catalogUrl($slug));
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

        $imgs = [];
        foreach ($imagesByPost[$pid] ?? [] as $im) {
            $token  = self::mStr($im, 'token');
            $imgs[] = [
                'full_url'  => $this->fileUrl($token, false),
                'thumb_url' => $this->fileUrl($token, true),
                'w'         => self::mInt($im, 'width'),
                'h'         => self::mInt($im, 'height'),
                'tw'        => self::mInt($im, 'thumb_w'),
                'th'        => self::mInt($im, 'thumb_h'),
                'spoiler'   => self::mBool($im, 'spoiler'),
                'orig'      => self::mStr($im, 'orig_name'),
            ];
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
        ];
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
