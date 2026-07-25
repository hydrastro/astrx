<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Admin\AuditLogger;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Csrf\CsrfHandler;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Imageboard\BanRepository;
use AstrX\Imageboard\BoardModRepository;
use AstrX\Imageboard\BoardRepository;
use AstrX\Imageboard\ImageBlockRepository;
use AstrX\Imageboard\ImageRepository;
use AstrX\Imageboard\PostRepository;
use AstrX\Imageboard\ReportRepository;
use AstrX\Imageboard\ThreadRepository;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\UrlGenerator;
use AstrX\Session\PrgHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\User\UserRepository;
use AstrX\User\UserSession;
use PDO;
use function AstrX\Support\langDir;

/**
 * Per-board moderation surface — one hidden template=1 page (WORDING_BOARD_MOD,
 * file_name 'board_mod') reached by link, never listed. It renders a dashboard
 * and the reports / bans / blocks / staff / settings views chosen from the
 * query string, plus the per-post and per-thread moderation panels and the
 * public report form:
 *
 *   ?board=<slug>                 → dashboard (open-report count + modlog)
 *   ?board=<slug>&view=reports    → open report queue
 *   ?board=<slug>&view=bans       → active bans
 *   ?board=<slug>&view=blocks     → image blocklist
 *   ?board=<slug>&view=staff      → per-board staff roster        (BOARD_ADMIN)
 *   ?board=<slug>&view=settings   → board settings                (BOARD_ADMIN)
 *   ?board=<slug>&post=<no>       → moderate one post
 *   ?board=<slug>&thread=<id>     → moderate one thread
 *   ?board=<slug>&report=<no>     → file a report                 (BOARD_POST)
 *
 * Every action is a plain PRG POST (no JavaScript). The mod views require
 * BOARD_MODERATE; the report form + its file_report action require only
 * BOARD_POST so an ordinary poster can report a post.
 */
final class BoardModController extends AbstractController
{
    private const FORM = 'board_mod';

    /** Enum allowlists — posted values are validated against these before use. */
    private const ROLES        = ['janitor', 'moderator'];
    private const CATEGORIES   = ['spam', 'illegal', 'offtopic', 'other'];
    private const FLAGS_MODES  = ['off', 'user', 'geo'];
    private const LIFECYCLES   = ['ephemeral', 'archive', 'persistent'];
    private const SMALLINT_MAX = 65535;

    public function __construct(
        DiagnosticsCollector                    $collector,
        private readonly DefaultTemplateContext $ctx,
        private readonly Request                $request,
        private readonly Gate                   $gate,
        private readonly CsrfHandler            $csrf,
        private readonly PrgHandler             $prg,
        private readonly UrlGenerator           $urlGen,
        private readonly Translator             $t,
        private readonly UserSession            $session,
        private readonly BoardRepository        $boards,
        private readonly ThreadRepository       $threads,
        private readonly PostRepository         $posts,
        private readonly ImageRepository        $images,
        private readonly ReportRepository       $reports,
        private readonly BanRepository          $bans,
        private readonly ImageBlockRepository   $blocks,
        private readonly BoardModRepository     $mods,
        private readonly UserRepository         $users,
        private readonly AuditLogger            $audit,
        private readonly PDO                    $pdo,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $this->t->loadDomain(langDir(), 'Imageboard');

        $slug  = self::queryStr($this->request, 'board');
        $bR    = $this->boards->bySlug($slug);
        $board = $bR->isOk() ? $bR->unwrap() : null;
        if (!is_array($board)) {
            http_response_code(404);
            exit;
        }

        $this->setCommon($board);

        // PRG replay of a posted mod form.
        $prgToken = $this->request->query()->get($this->prg->tokenQueryKey());
        if (is_string($prgToken) && $prgToken !== '') {
            $redirect = $this->process($prgToken, $board);
            Response::redirect($redirect)->send()->drainTo($this->collector);
            exit;
        }

        // ── GET views ─────────────────────────────────────────────────────────
        // The report form is reachable by ordinary posters (BOARD_POST); every
        // other view is moderator-only (BOARD_MODERATE), and staff/settings are
        // narrowed further to BOARD_ADMIN inside their view methods.
        $reportNo = self::queryInt($this->request, 'report');
        if ($reportNo > 0) {
            if ($this->gate->cannot(Permission::BOARD_POST)) {
                http_response_code(404);
                exit;
            }
            return $this->viewReportForm($board, $reportNo);
        }

        if ($this->gate->cannot(Permission::BOARD_MODERATE)) {
            http_response_code(404);
            exit;
        }

        $postNo = self::queryInt($this->request, 'post');
        if ($postNo > 0) {
            return $this->viewModeratePost($board, $postNo);
        }
        $threadId = self::queryInt($this->request, 'thread');
        if ($threadId > 0) {
            return $this->viewModerateThread($board, $threadId);
        }

        return match (self::queryStr($this->request, 'view')) {
            'reports'  => $this->viewReports($board),
            'bans'     => $this->viewBans($board),
            'blocks'   => $this->viewBlocks($board),
            'staff'    => $this->viewStaff($board),
            'settings' => $this->viewSettings($board),
            default    => $this->viewDashboard($board),
        };
    }

    // ── GET views ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function viewDashboard(array $board): Result
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $this->ctx->set('is_dashboard', true);

        $cR = $this->reports->countOpen($bid);
        $this->ctx->set('open_reports', $cR->isOk() ? $cR->unwrap() : 0);

        $modlog = $this->modlog($slug);
        $this->ctx->set('modlog',     $modlog);
        $this->ctx->set('has_modlog', $modlog !== []);
        return $this->ok();
    }

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function viewReports(array $board): Result
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $this->ctx->set('is_reports', true);
        $this->seedForm($this->modBase($slug) . '&view=reports');

        $rR   = $this->reports->open($bid);
        $rows = $rR->isOk() ? $rR->unwrap() : [];
        $list = [];
        foreach ($rows as $row) {
            $no  = self::mInt($row, 'post_no');
            $tid = self::mInt($row, 'thread_id');
            $list[] = [
                'report_id'  => self::mInt($row, 'report_id'),
                'category'   => self::mStr($row, 'category'),
                'post_no'    => $no,
                'reason'     => self::mStr($row, 'reason'),
                'excerpt'    => $this->excerpt(self::mStr($row, 'body_html')),
                'thread_url' => $this->boardThreadUrl($slug, $tid) . '#p' . $no,
                'post_url'   => $this->modBase($slug) . '&post=' . $no,
            ];
        }
        $this->ctx->set('reports',     $list);
        $this->ctx->set('has_reports', $list !== []);
        return $this->ok();
    }

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function viewBans(array $board): Result
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $this->ctx->set('is_bans', true);
        $this->seedForm($this->modBase($slug) . '&view=bans');

        $bnR  = $this->bans->activeForBoard($bid);
        $rows = $bnR->isOk() ? $bnR->unwrap() : [];
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'ban_id'  => self::mInt($row, 'ban_id'),
                'global'  => ($row['board_id'] ?? null) === null,
                'target'  => $this->banTarget($row),
                'expires' => $this->banExpires($row),
                'reason'  => self::mStr($row, 'reason'),
            ];
        }
        $this->ctx->set('bans',     $list);
        $this->ctx->set('has_bans', $list !== []);
        return $this->ok();
    }

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function viewBlocks(array $board): Result
    {
        $slug = self::mStr($board, 'slug');
        $this->ctx->set('is_blocks', true);
        $this->seedForm($this->modBase($slug) . '&view=blocks');

        $blR  = $this->blocks->all();
        $rows = $blR->isOk() ? $blR->unwrap() : [];
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'block_id' => self::mInt($row, 'id'),
                'kind'     => $this->blockKind($row),
                'reason'   => self::mStr($row, 'reason'),
            ];
        }
        $this->ctx->set('blocks',     $list);
        $this->ctx->set('has_blocks', $list !== []);
        return $this->ok();
    }

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function viewStaff(array $board): Result
    {
        if ($this->gate->cannot(Permission::BOARD_ADMIN)) {
            http_response_code(404);
            exit;
        }
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $this->ctx->set('is_staff', true);
        $this->seedForm($this->modBase($slug) . '&view=staff');

        $rR   = $this->mods->roster($bid);
        $rows = $rR->isOk() ? $rR->unwrap() : [];
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'user_id'  => self::mStr($row, 'user_id'),
                'username' => self::mStr($row, 'username'),
                'role'     => self::mStr($row, 'role'),
            ];
        }
        $this->ctx->set('roster', $list);
        return $this->ok();
    }

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function viewSettings(array $board): Result
    {
        if ($this->gate->cannot(Permission::BOARD_ADMIN)) {
            http_response_code(404);
            exit;
        }
        $slug = self::mStr($board, 'slug');
        $this->ctx->set('is_settings', true);
        $this->seedForm($this->modBase($slug) . '&view=settings');

        $this->ctx->set('s_title',        self::mStr($board, 'title'));
        $this->ctx->set('s_subtitle',     self::mStr($board, 'subtitle'));
        $this->ctx->set('s_description',  self::mStr($board, 'description'));
        $this->ctx->set('s_nsfw',         self::mBool($board, 'nsfw'));
        $this->ctx->set('s_forced_anon',  self::mBool($board, 'forced_anon'));
        $this->ctx->set('s_bbcode',       self::mBool($board, 'bbcode'));
        $this->ctx->set('s_poster_ids',   self::mBool($board, 'poster_ids'));
        $this->ctx->set('s_flags_mode',   self::mStr($board, 'flags_mode'));
        $this->ctx->set('s_lifecycle',    self::mStr($board, 'lifecycle'));
        $this->ctx->set('s_bump_limit',   self::mInt($board, 'bump_limit'));
        $this->ctx->set('s_image_limit',  self::mInt($board, 'image_limit'));
        $this->ctx->set('s_thread_limit', self::mInt($board, 'thread_limit'));
        $this->ctx->set('s_max_post_len', self::mInt($board, 'max_post_len'));
        $this->ctx->set('s_cooldown',     self::mInt($board, 'cooldown_secs'));
        return $this->ok();
    }

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function viewModeratePost(array $board, int $no): Result
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $pR   = $this->posts->byBoardNo($bid, $no);
        $post = $pR->isOk() ? $pR->unwrap() : null;
        if (!is_array($post)) {
            return $this->viewDashboard($board);
        }

        $pid = self::mInt($post, 'id');
        $tid = self::mInt($post, 'thread_id');
        $this->ctx->set('is_moderate_post', true);
        $this->seedForm($this->modBase($slug) . '&post=' . $no);
        $this->ctx->set('mp_no',         $no);
        $this->ctx->set('mp_body',       self::mStr($post, 'body_html'));
        $this->ctx->set('mp_post_id',    $pid);
        $this->ctx->set('mp_can_ban',    $this->gate->can(Permission::BOARD_MODERATE));
        $this->ctx->set('mp_has_image',  $this->postImages($pid) !== []);
        $this->ctx->set('mp_thread_url', $this->boardThreadUrl($slug, $tid));
        return $this->ok();
    }

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function viewModerateThread(array $board, int $threadId): Result
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $tR     = $this->threads->byId($threadId);
        $thread = $tR->isOk() ? $tR->unwrap() : null;
        if (!is_array($thread) || self::mInt($thread, 'board_id') !== $bid) {
            return $this->viewDashboard($board);
        }
        $this->ctx->set('is_moderate_thread', true);
        $this->seedForm($this->modBase($slug) . '&thread=' . $threadId);
        $this->ctx->set('mt_subject',    self::mStr($thread, 'subject'));
        $this->ctx->set('mt_thread_id',  $threadId);
        $this->ctx->set('mt_sticky',     self::mBool($thread, 'sticky'));
        $this->ctx->set('mt_locked',     self::mBool($thread, 'locked'));
        $this->ctx->set('mt_cycle',      self::mBool($thread, 'cycle'));
        $this->ctx->set('mt_autosage',   self::mBool($thread, 'autosage'));
        $this->ctx->set('mt_thread_url', $this->boardThreadUrl($slug, $threadId));
        return $this->ok();
    }

    /**
     * @param array<string,mixed> $board
     * @return Result<mixed>
     */
    private function viewReportForm(array $board, int $no): Result
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $pR   = $this->posts->byBoardNo($bid, $no);
        $post = $pR->isOk() ? $pR->unwrap() : null;
        if (!is_array($post)) {
            http_response_code(404);
            exit;
        }
        $this->ctx->set('is_report_form', true);
        $this->seedForm($this->modBase($slug) . '&report=' . $no);
        $this->ctx->set('rf_no',        $no);
        $this->ctx->set('rf_excerpt',   $this->excerpt(self::mStr($post, 'body_html')));
        $this->ctx->set('rf_post_id',   self::mInt($post, 'id'));
        $this->ctx->set('rf_thread_id', self::mInt($post, 'thread_id'));
        return $this->ok();
    }

    // ── POST (PRG) dispatch ─────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $board
     * @return string redirect URL
     */
    private function process(string $prgToken, array $board): string
    {
        $slug   = self::mStr($board, 'slug');
        $posted = $this->prg->pull($prgToken) ?? [];
        $action = self::mStr($posted, 'mod_action');

        $csrf = $this->csrf->verify(self::FORM, self::mStr($posted, '_csrf'));
        if (!$csrf->isOk()) {
            $csrf->drainTo($this->collector);
            return $this->modBase($slug);
        }

        // Ordinary-poster branch: filing a report needs only BOARD_POST.
        if ($action === 'file_report') {
            if ($this->gate->cannot(Permission::BOARD_POST)) {
                return $this->boardUrl($slug);
            }
            return $this->doFileReport($board, $posted);
        }

        // Everything else is moderator-only (admin-only actions re-check inside).
        if ($this->gate->cannot(Permission::BOARD_MODERATE)) {
            return $this->modBase($slug);
        }

        return match ($action) {
            'resolve_report' => $this->doResolveReport($board, $posted),
            'lift_ban'       => $this->doLiftBan($board, $posted),
            'unblock_image'  => $this->doUnblockImage($board, $posted),
            'delete_post'    => $this->doDeletePost($board, $posted),
            'ban_post'       => $this->doBanPost($board, $posted),
            'block_image'    => $this->doBlockImage($board, $posted),
            'thread_flag'    => $this->doThreadFlag($board, $posted),
            'delete_thread'  => $this->doDeleteThread($board, $posted),
            'grant_mod'      => $this->doGrantMod($board, $posted),
            'revoke_mod'     => $this->doRevokeMod($board, $posted),
            'save_settings'  => $this->doSaveSettings($board, $posted),
            default          => $this->modBase($slug),
        };
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doResolveReport(array $board, array $posted): string
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $reportId = self::mInt($posted, 'report_id');
        $this->reports->resolve($reportId, $bid)->drainTo($this->collector);
        $this->audit->log('board.resolve_report', 'board:' . $slug, 'report #' . $reportId);
        return $this->modBase($slug) . '&view=reports';
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doLiftBan(array $board, array $posted): string
    {
        $slug  = self::mStr($board, 'slug');
        $banId = self::mInt($posted, 'ban_id');
        $this->bans->lift($banId)->drainTo($this->collector);
        $this->audit->log('board.lift_ban', 'board:' . $slug, 'ban #' . $banId);
        return $this->modBase($slug) . '&view=bans';
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doUnblockImage(array $board, array $posted): string
    {
        $slug    = self::mStr($board, 'slug');
        $blockId = self::mInt($posted, 'block_id');
        $this->blocks->remove($blockId)->drainTo($this->collector);
        $this->audit->log('board.unblock_image', 'board:' . $slug, 'block #' . $blockId);
        return $this->modBase($slug) . '&view=blocks';
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doDeletePost(array $board, array $posted): string
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $post = $this->lookupBoardPost($bid, self::mInt($posted, 'post_id'));
        if ($post === null) {
            return $this->modBase($slug);
        }
        $this->deletePostWithCounts($post);
        $this->audit->log('board.delete_post', 'board:' . $slug, 'No.' . self::mInt($post, 'no'));
        return $this->modBase($slug);
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doBanPost(array $board, array $posted): string
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $postId = self::mInt($posted, 'post_id');
        $post   = $this->lookupBoardPost($bid, $postId);
        if ($post === null) {
            return $this->modBase($slug);
        }

        $no      = self::mInt($post, 'no');
        $global  = self::mStr($posted, 'scope') === 'global';
        $days    = max(0, min(3650, self::mInt($posted, 'days')));
        $reason  = self::mStr($posted, 'reason');
        $alsoDel = self::mBool($posted, 'also_delete');

        // Account lever: the offending post's authenticated user, if any.
        $hexUserId = null;
        if (self::mBool($posted, 'ban_account')) {
            $uid = self::mStr($post, 'user_id');
            $hexUserId = $uid !== '' ? $uid : null;
        }

        // IP lever: the post's stored packed IP (absent on the onion default).
        $packedIp  = null;
        $prefixLen = 128;
        if (self::mBool($posted, 'ban_ip')) {
            $ipR = $this->posts->packedIpById($postId);
            $ip  = $ipR->isOk() ? $ipR->unwrap() : null;
            if (is_string($ip)) {
                $packedIp  = $ip;
                $prefixLen = strlen($ip) === 4 ? 32 : 128;
            }
        }

        // Only record a ban if it keys on at least one lever.
        if ($hexUserId !== null || $packedIp !== null) {
            $createdBy = $this->session->userId();
            $this->bans->create(
                $global ? null : $bid,
                $hexUserId,
                $packedIp,
                $prefixLen,
                $reason,
                '',
                $postId,
                $createdBy !== '' ? $createdBy : null,
                $days,
            )->drainTo($this->collector);
            $this->audit->log(
                'board.ban_post',
                'board:' . $slug,
                'No.' . $no . ($global ? ' global' : '') . ($days > 0 ? ' ' . $days . 'd' : ' perm'),
            );
        }

        if ($alsoDel) {
            $this->deletePostWithCounts($post);
            $this->audit->log('board.delete_post', 'board:' . $slug, 'No.' . $no);
            return $this->modBase($slug);
        }
        return $this->modBase($slug) . '&post=' . $no;
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doBlockImage(array $board, array $posted): string
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $postId = self::mInt($posted, 'post_id');
        $post   = $this->lookupBoardPost($bid, $postId);
        if ($post === null) {
            return $this->modBase($slug);
        }

        $no      = self::mInt($post, 'no');
        $reason  = self::mStr($posted, 'reason');
        $alsoDel = self::mBool($posted, 'also_delete');

        // Block by exact content hash (sha256). Perceptual (ahash) blocking is
        // left at 0 here — the exact hash is the robust, unambiguous lever.
        foreach ($this->postImages($postId) as $img) {
            $sha = self::mStr($img, 'sha256');
            if ($sha !== '') {
                $this->blocks->create($sha, 0, $reason)->drainTo($this->collector);
            }
        }
        $this->audit->log('board.block_image', 'board:' . $slug, 'No.' . $no);

        if ($alsoDel) {
            $this->deletePostWithCounts($post);
            $this->audit->log('board.delete_post', 'board:' . $slug, 'No.' . $no);
            return $this->modBase($slug);
        }
        return $this->modBase($slug) . '&post=' . $no;
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doThreadFlag(array $board, array $posted): string
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $threadId = self::mInt($posted, 'thread_id');
        $flag     = self::mStr($posted, 'flag');
        $value    = self::mBool($posted, 'value');

        $thread = $this->lookupBoardThread($bid, $threadId);
        if ($thread !== null) {
            $this->threads->setFlag($threadId, $flag, $value)->drainTo($this->collector);
            $this->audit->log(
                'board.thread_flag',
                'board:' . $slug,
                $flag . '=' . ($value ? '1' : '0') . ' thread #' . $threadId,
            );
        }
        return $this->modBase($slug) . '&thread=' . $threadId;
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doDeleteThread(array $board, array $posted): string
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $threadId = self::mInt($posted, 'thread_id');

        if ($this->lookupBoardThread($bid, $threadId) !== null) {
            $this->threads->delete($threadId)->drainTo($this->collector);
            $this->audit->log('board.delete_thread', 'board:' . $slug, 'thread #' . $threadId);
        }
        return $this->modBase($slug);
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doGrantMod(array $board, array $posted): string
    {
        $slug = self::mStr($board, 'slug');
        if ($this->gate->cannot(Permission::BOARD_ADMIN)) {
            return $this->modBase($slug) . '&view=staff';
        }
        $bid      = self::mInt($board, 'id');
        $username = self::mStr($posted, 'username');
        $roleRaw  = self::mStr($posted, 'role');
        $role     = in_array($roleRaw, self::ROLES, true) ? $roleRaw : 'janitor';

        $uR   = $this->users->findByUsername($username);
        $user = $uR->isOk() ? $uR->unwrap() : null;
        if (is_array($user)) {
            $hexId = self::mStr($user, 'id');
            if ($hexId !== '') {
                $this->mods->grant($bid, $hexId, $role)->drainTo($this->collector);
                $this->audit->log('board.grant_mod', 'board:' . $slug, $username . ' ' . $role);
            }
        }
        return $this->modBase($slug) . '&view=staff';
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doRevokeMod(array $board, array $posted): string
    {
        $slug = self::mStr($board, 'slug');
        if ($this->gate->cannot(Permission::BOARD_ADMIN)) {
            return $this->modBase($slug) . '&view=staff';
        }
        $bid   = self::mInt($board, 'id');
        $hexId = self::mStr($posted, 'user_id');
        if ($hexId !== '' && ctype_xdigit($hexId)) {
            $this->mods->revoke($bid, $hexId)->drainTo($this->collector);
            $this->audit->log('board.revoke_mod', 'board:' . $slug, $hexId);
        }
        return $this->modBase($slug) . '&view=staff';
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doSaveSettings(array $board, array $posted): string
    {
        $slug = self::mStr($board, 'slug');
        if ($this->gate->cannot(Permission::BOARD_ADMIN)) {
            return $this->modBase($slug) . '&view=settings';
        }
        $bid = self::mInt($board, 'id');

        $flagsRaw  = self::mStr($posted, 'flags_mode');
        $flagsMode = in_array($flagsRaw, self::FLAGS_MODES, true) ? $flagsRaw : self::mStr($board, 'flags_mode');
        $lifeRaw   = self::mStr($posted, 'lifecycle');
        $lifecycle = in_array($lifeRaw, self::LIFECYCLES, true) ? $lifeRaw : self::mStr($board, 'lifecycle');

        $this->boards->saveModSettings(
            $bid,
            mb_substr(self::mStr($posted, 'title'), 0, 128),
            mb_substr(self::mStr($posted, 'subtitle'), 0, 255),
            self::mStr($posted, 'description'),
            self::mBool($posted, 'nsfw'),
            self::mBool($posted, 'forced_anon'),
            self::mBool($posted, 'bbcode'),
            self::mBool($posted, 'poster_ids'),
            $flagsMode,
            $lifecycle,
            $this->clampSmall(self::mInt($posted, 'bump_limit')),
            $this->clampSmall(self::mInt($posted, 'image_limit')),
            $this->clampSmall(self::mInt($posted, 'thread_limit')),
            $this->clampSmall(self::mInt($posted, 'max_post_len')),
            $this->clampSmall(self::mInt($posted, 'cooldown_secs')),
        )->drainTo($this->collector);
        $this->audit->log('board.save_settings', 'board:' . $slug, '');
        return $this->modBase($slug) . '&view=settings';
    }

    /**
     * @param array<string,mixed> $board
     * @param array<string,mixed> $posted
     */
    private function doFileReport(array $board, array $posted): string
    {
        $bid  = self::mInt($board, 'id');
        $slug = self::mStr($board, 'slug');
        $postId   = self::mInt($posted, 'post_id');
        $threadId = self::mInt($posted, 'thread_id');
        $post = $this->lookupBoardPost($bid, $postId);
        if ($post === null) {
            return $this->boardUrl($slug);
        }
        $catRaw   = self::mStr($posted, 'category');
        $category = in_array($catRaw, self::CATEGORIES, true) ? $catRaw : 'other';
        $reason   = self::mStr($posted, 'reason');

        // Tor-safe reporter identity: a hash of the session id — never a raw IP.
        // Falls back to a per-post constant if the session id is unavailable.
        $sid   = session_id();
        $seed  = is_string($sid) && $sid !== '' ? $sid : $slug . ':' . $postId;
        $ident = hash('sha256', $seed);

        $this->reports->create($postId, $bid, $ident, $category, $reason)->drainTo($this->collector);
        $this->audit->log('board.file_report', 'board:' . $slug, 'No.' . self::mInt($post, 'no'));

        return $threadId > 0 ? $this->boardThreadUrl($slug, $threadId) : $this->boardUrl($slug);
    }

    // ── Shared helpers ──────────────────────────────────────────────────────────

    /**
     * Look up a post by id and confirm it belongs to this board (defence against
     * a crafted post_id targeting another board).
     *
     * @return array<string,mixed>|null
     */
    private function lookupBoardPost(int $boardId, int $postId): ?array
    {
        if ($postId <= 0) {
            return null;
        }
        $pR   = $this->posts->byId($postId);
        $post = $pR->isOk() ? $pR->unwrap() : null;
        if (!is_array($post) || self::mInt($post, 'board_id') !== $boardId) {
            return null;
        }
        return $post;
    }

    /**
     * Look up a thread by id and confirm it belongs to this board.
     *
     * @return array<string,mixed>|null
     */
    private function lookupBoardThread(int $boardId, int $threadId): ?array
    {
        if ($threadId <= 0) {
            return null;
        }
        $tR     = $this->threads->byId($threadId);
        $thread = $tR->isOk() ? $tR->unwrap() : null;
        if (!is_array($thread) || self::mInt($thread, 'board_id') !== $boardId) {
            return null;
        }
        return $thread;
    }

    /**
     * Delete a post and keep its thread's counters consistent: only a reply
     * decrements reply_count (the OP is not a reply), and every image on the
     * post decrements image_count.
     *
     * @param array<string,mixed> $post
     */
    private function deletePostWithCounts(array $post): void
    {
        $pid = self::mInt($post, 'id');
        $tid = self::mInt($post, 'thread_id');
        $imgCount = count($this->postImages($pid));

        $this->posts->delete($pid)->drainTo($this->collector);
        $this->threads
            ->adjustCounts($tid, self::mBool($post, 'is_op') ? 0 : -1, -$imgCount)
            ->drainTo($this->collector);
    }

    /**
     * The image rows attached to a post (empty when it has none).
     *
     * @return list<array<string,mixed>>
     */
    private function postImages(int $postId): array
    {
        $iR  = $this->images->forPosts([$postId]);
        $map = $iR->isOk() ? $iR->unwrap() : [];
        return $map[$postId] ?? [];
    }

    /**
     * The last ~30 audit-log rows scoped to this board (feature 13: modlog).
     *
     * @return list<array{time:string,username:string,action:string,detail:string}>
     */
    private function modlog(string $slug): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT DATE_FORMAT(`created_at`, '%Y-%m-%d %H:%i') AS `time`,
                        `username`, `action`, `detail`
                   FROM `admin_audit_log`
                  WHERE `resource` = :res
                  ORDER BY `created_at` DESC, `id` DESC
                  LIMIT 30"
            );
            $stmt->execute([':res' => 'board:' . $slug]);
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'time'     => self::mStr($row, 'time'),
                'username' => self::mStr($row, 'username'),
                'action'   => self::mStr($row, 'action'),
                'detail'   => self::mStr($row, 'detail'),
            ];
        }
        return $out;
    }

    /** Human target label for a ban row (username, hex-id prefix, or IP/CIDR). */
    /** @param array<string,mixed> $row */
    private function banTarget(array $row): string
    {
        $username = self::mStr($row, 'username');
        if ($username !== '') {
            return $username;
        }
        $uid = self::mStr($row, 'user_id');
        if ($uid !== '') {
            return substr($uid, 0, 12);
        }
        $ipHex = self::mStr($row, 'ip_hex');
        if ($ipHex !== '' && ctype_xdigit($ipHex) && strlen($ipHex) % 2 === 0) {
            $bin = hex2bin($ipHex);
            if (is_string($bin) && in_array(strlen($bin), [4, 16], true)) {
                $ip = inet_ntop($bin);
                if (is_string($ip)) {
                    return $ip . '/' . self::mInt($row, 'prefix_len');
                }
            }
        }
        return '—';
    }

    /** Expiry label for a ban row: a formatted date, or the "permanent" i18n label. */
    /** @param array<string,mixed> $row */
    private function banExpires(array $row): string
    {
        $ts = $row['expires_ts'] ?? null;
        if ($ts === null || !is_numeric($ts)) {
            return $this->t->t('board.mod.permanent');
        }
        return date('Y-m-d H:i', (int) $ts);
    }

    /** Short technical descriptor of an image block (which hash it keys on). */
    /** @param array<string,mixed> $row */
    private function blockKind(array $row): string
    {
        $sha = self::mStr($row, 'sha256');
        if ($sha !== '') {
            return 'sha256:' . substr($sha, 0, 16);
        }
        $ahash = self::mStr($row, 'ahash');
        if ($ahash !== '' && $ahash !== '0') {
            return 'ahash:' . $ahash;
        }
        return '—';
    }

    /** Collapse post HTML to a short plain-text excerpt. */
    private function excerpt(string $html): string
    {
        $stripped  = strip_tags($html);
        $collapsed = preg_replace('/\s+/', ' ', $stripped) ?? $stripped;
        return mb_strimwidth(trim($collapsed), 0, 160, '…');
    }

    private function clampSmall(int $v): int
    {
        return max(0, min(self::SMALLINT_MAX, $v));
    }

    // ── Context / URL setup ─────────────────────────────────────────────────────

    /**
     * Set the nav flags, section URLs, labels and board identity shared by every
     * view. Per-section form seeding (prg_id / csrf / form_action) is done by the
     * individual view methods.
     *
     * @param array<string,mixed> $board
     */
    private function setCommon(array $board): void
    {
        $slug = self::mStr($board, 'slug');
        $base = $this->modBase($slug);

        $this->ctx->set('board_slug',  $slug);
        $this->ctx->set('board_title', self::mStr($board, 'title'));

        $this->ctx->set('mod_base',     $base);
        $this->ctx->set('reports_url',  $base . '&view=reports');
        $this->ctx->set('bans_url',     $base . '&view=bans');
        $this->ctx->set('blocks_url',   $base . '&view=blocks');
        $this->ctx->set('staff_url',    $base . '&view=staff');
        $this->ctx->set('settings_url', $base . '&view=settings');
        $this->ctx->set('board_url',    $this->boardUrl($slug));

        $canMod = $this->gate->can(Permission::BOARD_MODERATE);
        $this->ctx->set('can_reports', $canMod);
        $this->ctx->set('can_ban',     $canMod);
        $this->ctx->set('can_manage',  $this->gate->can(Permission::BOARD_ADMIN));

        $this->setLabels();
    }

    private function seedForm(string $actionUrl): void
    {
        $this->ctx->set('form_action', $actionUrl);
        $this->ctx->set('prg_id',      $this->prg->createId($actionUrl));
        $this->ctx->set('csrf_token',  $this->csrf->generate(self::FORM));
    }

    private function modBase(string $slug): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_BOARD_MOD')) . '?board=' . rawurlencode($slug);
    }

    private function boardUrl(string $slug): string
    {
        return $this->urlGen->toPage($this->t->t('WORDING_BOARD')) . '/' . rawurlencode($slug);
    }

    private function boardThreadUrl(string $slug, int $threadId): string
    {
        return $this->boardUrl($slug) . '/thread/' . $threadId;
    }

    private function setLabels(): void
    {
        foreach ([
            'lbl_mod_dashboard'     => 'board.mod.dashboard',
            'lbl_mod_reports'       => 'board.mod.reports',
            'lbl_mod_bans'          => 'board.mod.bans',
            'lbl_mod_blocks'        => 'board.mod.blocks',
            'lbl_mod_staff'         => 'board.mod.staff',
            'lbl_mod_settings'      => 'board.mod.settings',
            'lbl_mod_back'          => 'board.mod.back',
            'lbl_mod_open_reports'  => 'board.mod.open_reports',
            'lbl_mod_no_reports'    => 'board.mod.no_reports',
            'lbl_mod_report'        => 'board.mod.report',
            'lbl_mod_ban'           => 'board.mod.ban',
            'lbl_mod_delete'        => 'board.mod.delete',
            'lbl_mod_resolve'       => 'board.mod.resolve',
            'lbl_mod_lift'          => 'board.mod.lift',
            'lbl_mod_unblock'       => 'board.mod.unblock',
            'lbl_mod_revoke'        => 'board.mod.revoke',
            'lbl_mod_username'      => 'board.mod.username',
            'lbl_mod_role'          => 'board.mod.role',
            'lbl_mod_janitor'       => 'board.mod.janitor',
            'lbl_mod_moderator'     => 'board.mod.moderator',
            'lbl_mod_grant'         => 'board.mod.grant',
            'lbl_mod_reason_hdr'    => 'board.mod.reason_hdr',
            'lbl_mod_target'        => 'board.mod.target',
            'lbl_mod_scope_board'   => 'board.mod.scope_board',
            'lbl_mod_scope_global'  => 'board.mod.scope_global',
            'lbl_mod_days'          => 'board.mod.days',
            'lbl_mod_also_delete'   => 'board.mod.also_delete',
            'lbl_mod_block_image'   => 'board.mod.block_image',
            'lbl_mod_sticky'        => 'board.mod.sticky',
            'lbl_mod_locked'        => 'board.mod.locked',
            'lbl_mod_cycle'         => 'board.mod.cycle',
            'lbl_mod_autosage'      => 'board.mod.autosage',
            'lbl_mod_delete_thread' => 'board.mod.delete_thread',
            'lbl_mod_category'      => 'board.mod.category',
            'lbl_mod_reason'        => 'board.mod.reason',
            'lbl_mod_submit_report' => 'board.mod.submit_report',
            'lbl_mod_save'          => 'board.mod.save',
            'lbl_mod_modlog'        => 'board.mod.modlog',
            'lbl_mod_no_modlog'     => 'board.mod.no_modlog',
        ] as $ctxKey => $tKey) {
            $this->ctx->set($ctxKey, $this->t->t($tKey));
        }
    }
}
