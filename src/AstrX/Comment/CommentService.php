<?php
declare(strict_types=1);

namespace AstrX\Comment;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Auth\Policy\CommentPolicy;
use AstrX\Comment\Diagnostic\CommentNotAllowedDiagnostic;
use AstrX\Comment\Diagnostic\CommentEmptyContentDiagnostic;
use AstrX\Comment\Diagnostic\CommentInvalidEmailDiagnostic;
use AstrX\Comment\Diagnostic\CommentReplyNotFoundDiagnostic;
use AstrX\Comment\Diagnostic\CommentReplyWrongPageDiagnostic;
use AstrX\Comment\Diagnostic\CommentAntispamDiagnostic;
use AstrX\Comment\Diagnostic\CommentMutedDiagnostic;
use AstrX\Comment\Diagnostic\CommentFloodDiagnostic;
use AstrX\Comment\Diagnostic\CommentGateDeniedDiagnostic;
use AstrX\Comment\Diagnostic\CommentNotFoundDiagnostic;
use AstrX\Config\InjectConfig;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\User\UserSession;

/**
 * Comment business logic.
 *
 * Handles permission checks, flood/antispam protection, tree assembly,
 * and delegates all DB work to CommentRepository.
 *
 * Flood: per-IP (guests) or per-user; configurable minimum seconds.
 * Antispam: configurable regex array, same structure as UserService password rules.
 * Tree: assembles flat DB rows into nested reply chains for display.
 */
final class CommentService
{
    // Config defaults
    private int   $commentsPerPage    = 20;
    private bool  $allowReplies       = true;
    private bool  $requireEmail       = false;
    private int   $minimumFloodSecs   = 10;
    private int   $antispamTimeSecs   = 30;
    /** @var array<int,array{regex:string,enabled:bool,message:string}> */
    private array $antispamRegex      = [];

    #[InjectConfig('comments_per_page')]
    public function setCommentsPerPage(int $v): void { $this->commentsPerPage = max(1, $v); }
    #[InjectConfig('allow_replies')]
    public function setAllowReplies(bool $v): void   { $this->allowReplies = $v; }
    #[InjectConfig('require_email')]
    public function setRequireEmail(bool $v): void   { $this->requireEmail = $v; }
    #[InjectConfig('minimum_flood_secs')]
    public function setMinimumFloodSecs(int $v): void { $this->minimumFloodSecs = max(0, $v); }
    #[InjectConfig('antispam_time_secs')]
    public function setAntispamTimeSecs(int $v): void { $this->antispamTimeSecs = max(0, $v); }
    /** @param array<int,array{regex:string,enabled:bool,message:string}> $v */
    #[InjectConfig('antispam_regex')]
    public function setAntispamRegex(array $v): void { $this->antispamRegex = $v; }

    public function commentsPerPage(): int  { return $this->commentsPerPage; }
    public function allowReplies(): bool    { return $this->allowReplies; }
    public function requireEmail(): bool    { return $this->requireEmail; }

    // -------------------------------------------------------------------------

    public function __construct(
        private readonly CommentRepository $repo,
        private readonly UserSession       $session,
        private readonly Gate              $gate,
    ) {
        // Register CommentPolicy so Gate can evaluate .own permissions
        $this->gate->registerPolicy(\stdClass::class, new CommentPolicy());
    }

    // -------------------------------------------------------------------------
    // Public display
    // -------------------------------------------------------------------------

    /**
     * Fetch comments for a page, assembled as a tree.
     * Each entry gains a 'depth' key (0 = root).
     *
     * @return Result<list<array<string,mixed>>>
     */
    public function getCommentsForPage(
        int  $pageId,
        int  $pageNum     = 1,
        bool $descending  = false,
        ?int $itemId      = null,
        ?int $perPage     = null,  // null = use config default
    ): Result {
        $limit  = $perPage ?? $this->commentsPerPage;
        $offset = ($pageNum - 1) * $limit;
        $result = $this->repo->fetchForPage(
            $pageId, $descending, $limit, $offset, $itemId
        );
        if (!$result->isOk()) {
            return $result;
        }
        /** @var list<array<string,mixed>> $flatRows */
        $flatRows = $result->unwrap();
        return Result::ok($this->assembleTree($flatRows));
    }

    /** @return Result<int> */
    public function countForPage(int $pageId, ?int $itemId = null): Result
    {
        return $this->repo->countForPage($pageId, $itemId);
    }

    // -------------------------------------------------------------------------
    // Posting
    // -------------------------------------------------------------------------

    /**
     * Submit a new comment.
     *
     * @return Result<int> new comment id on success
     */
    public function post(
        int     $pageId,
        string  $content,
        ?string $name     = null,
        ?string $email    = null,
        ?int    $replyTo  = null,
        ?string $remoteIp = null,
        ?int    $itemId   = null,
    ): Result {
        // Permission check
        if ($this->gate->cannot(Permission::COMMENT_POST)) {
            return $this->opErr('not_allowed');
        }

        // Content must not be empty
        if (trim($content) === '') {
            return $this->opErr('empty_content');
        }

        // Anonymous commenters: require email if configured
        if (!$this->session->isLoggedIn()) {
            if ($this->requireEmail && ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                return $this->opErr('invalid_email');
            }
            // Store NULL for anonymous commenters with no name; the display
            // fallback (comment.anonymous) is rendered at the view layer so the
            // label stays translatable instead of a hardcoded English literal.
            $name = ($name !== null && trim($name) !== '') ? trim($name) : null;
        }

        // Reply validation
        if ($replyTo !== null) {
            $parentResult = $this->repo->findById($replyTo);
            if (!$parentResult->isOk() || $parentResult->unwrap() === null) {
                return $this->opErr('reply_not_found');
            }
            /** @var array<string,mixed> $parent */
            $parent = $parentResult->unwrap();
            $ppid = $parent['page_id'] ?? 0;
            $parentPageId = is_int($ppid) ? $ppid : 0;
            if ($parentPageId !== $pageId) {
                return $this->opErr('reply_wrong_page');
            }
            // A reply must also target the SAME item as its parent — page_id alone
            // does not pin the thread on a multi-item page, so without this an item
            // reply could attach across items (R3-26). item_id is NULL for
            // page-level comments; both sides must match (NULL == NULL).
            $pItem = $parent['item_id'] ?? null;
            $parentItemId = is_int($pItem) ? $pItem : (is_numeric($pItem) ? (int) $pItem : null);
            if ($parentItemId !== $itemId) {
                return $this->opErr('reply_wrong_page');
            }
        }

        // Antispam regex
        $spamErr = $this->checkAntispam($content);
        if ($spamErr !== null) {
            return $this->opErr('antispam', $spamErr);
        }

        // Build packed IP — the real REMOTE_ADDR. Kept as the MEMBER's stored IP,
        // but NOT used as the guest flood/mute key: on a Tor hidden service the app
        // only ever sees a single shared loopback/exit REMOTE_ADDR, so keying guest
        // flood + auto-mute on it makes one guest's cooldown/mute collide with every
        // other guest on the page (see $floodKey below).
        $ip = null;
        if ($remoteIp !== null && filter_var($remoteIp, FILTER_VALIDATE_IP)) {
            $packed = inet_pton($remoteIp);
            $ip = $packed !== false ? $packed : null;
        }

        $hexUserId = $this->session->isLoggedIn() ? $this->session->userId() : null;

        // Flood / mute key. MEMBERS keep the existing user-id keying with a null IP
        // (the mute/flood lookups then match on user_id alone). GUESTS key on a
        // stable per-visitor token instead of the shared IP — the same idea the
        // chat module uses (ChatService + ChatIdentity::guestRateKey) and the
        // imageboard uses for its per-session identity token. The token is 16 raw
        // bytes so it fits the VARBINARY(16) `ip` column the flood/mute lookups key
        // on (mute.ip and comment.ip). A session is always started before any
        // controller runs, so session_id() is available here.
        $floodKey = $hexUserId !== null
            ? null
            : substr(hash('sha256', 'comment-guest|' . session_id(), true), 0, 16);

        // What lands in comment.ip: members keep their real IP; guests store the
        // very token the flood lookup (lastCommentTime, keyed on comment.ip) reads
        // back, so a guest's cooldown matches their own prior comment — never a
        // different guest's — and never the shared REMOTE_ADDR.
        $storeIp = $hexUserId !== null ? $ip : $floodKey;

        // ── Mute check ────────────────────────────────────────────────
        $muteResult = $this->repo->isMuted($hexUserId, $floodKey, $pageId);
        if ($muteResult->isOk() && $muteResult->unwrap() === true) {
            return $this->opErr('muted');
        }

        // ── Flood check ───────────────────────────────────────────────
        // Comment moderators / admins are exempt from the post cooldown.
        // The lookup is page-scoped to match the page-scoped auto-mute and mute
        // check below — the keying is consistent (R3-18).
        $isStaff = $this->gate->can(Permission::ADMIN_COMMENTS);
        if (!$isStaff && $this->minimumFloodSecs > 0) {
            $lastResult = $this->repo->lastCommentTime($hexUserId, $floodKey, $pageId);
            if ($lastResult->isOk() && $lastResult->unwrap() !== null) {
                $lastTs = $lastResult->unwrap();
                $elapsed = time() - $lastTs;
                if ($elapsed < $this->minimumFloodSecs) {
                    if ($this->antispamTimeSecs > 0) {
                        $this->repo->addMute($hexUserId, $floodKey, $pageId, $this->antispamTimeSecs);
                    }
                    return $this->opErr('flood');
                }
            }
        }

        return $this->repo->create(
            $pageId, $hexUserId,
            $hexUserId !== null ? null : $name,
            $hexUserId !== null ? null : $email,
            $content, $replyTo, $storeIp, $itemId,
        );
    }

    // -------------------------------------------------------------------------
    // Moderation (used by admin controller and future public report flow)
    // -------------------------------------------------------------------------

    /** @return Result<bool> */
    public function hide(int $commentId): Result
    {
        $loaded = $this->loadResource($commentId);
        if (!$loaded->isOk()) {
            return Result::err($loaded->error(), $loaded->diagnostics());
        }
        $resource = $loaded->unwrap();
        if ($resource === null) {
            return $this->opErr('comment_not_found');
        }
        if ($this->gate->cannot(Permission::COMMENT_HIDE_ANY, $resource)) {
            return $this->opErr('gate_denied');
        }
        return $this->repo->setHidden($commentId, true);
    }

    /** @return Result<bool> */
    public function unhide(int $commentId): Result
    {
        $loaded = $this->loadResource($commentId);
        if (!$loaded->isOk()) {
            return Result::err($loaded->error(), $loaded->diagnostics());
        }
        $resource = $loaded->unwrap();
        if ($resource === null) {
            return $this->opErr('comment_not_found');
        }
        if ($this->gate->cannot(Permission::COMMENT_HIDE_ANY, $resource)) {
            return $this->opErr('gate_denied');
        }
        return $this->repo->setHidden($commentId, false);
    }

    /** @return Result<bool> */
    public function delete(int $commentId): Result
    {
        $loaded = $this->loadResource($commentId);
        if (!$loaded->isOk()) {
            return Result::err($loaded->error(), $loaded->diagnostics());
        }
        $resource = $loaded->unwrap();
        if ($resource === null) {
            return $this->opErr('comment_not_found');
        }
        if ($this->gate->cannot(Permission::COMMENT_DELETE_ANY, $resource)) {
            return $this->opErr('gate_denied');
        }
        return $this->repo->delete($commentId);
    }

    /**
     * Load a comment as a policy resource object so CommentPolicy can see the
     * author's user_type (needed to stop mods moderating admin comments).
     *
     * The repository returns a flat associative array; casting to object yields
     * the stdClass shape CommentPolicy is registered against. Propagates DB
     * failures; a missing comment resolves to Result::ok(null).
     *
     * @return Result<object|null>
     */
    private function loadResource(int $commentId): Result
    {
        $result = $this->repo->findById($commentId);
        if (!$result->isOk()) {
            return Result::err($result->error(), $result->diagnostics());
        }
        $row = $result->unwrap();
        return Result::ok($row === null ? null : (object) $row);
    }

    /** @return Result<bool> */
    public function flag(int $commentId): Result
    {
        if ($this->gate->cannot(Permission::COMMENT_FLAG)) {
            return $this->opErr('gate_denied');
        }
        return $this->repo->setFlagged($commentId, true);
    }

    // -------------------------------------------------------------------------
    // Tree assembly
    // -------------------------------------------------------------------------

    /**
     * Convert a flat ordered list into a depth-annotated list for template rendering.
     * Each row gains: depth (int), has_children (bool).
     *
     * @param  list<array<string,mixed>> $flat
     * @return list<array<string,mixed>>
     */
    public function assembleTree(array $flat): array
    {
        // Index by id
        $byId = [];
        foreach ($flat as $row) {
            /** @var array<string,mixed> $row */
            $rawId = $row['id'] ?? 0;
            $rowId = is_int($rawId) ? $rawId : 0;
            $byId[$rowId] = $row + ['depth' => 0, 'children' => []];
        }

        $roots = [];
        foreach ($byId as $id => &$row) {
            $rtRaw = $row['reply_to'] ?? null;
            $replyTo = ($rtRaw !== null && is_int($rtRaw)) ? $rtRaw : null;
            if ($replyTo === null || !isset($byId[$replyTo])) {
                $roots[] = &$row;
            } else {
                $childList = is_array($byId[$replyTo]['children']) ? $byId[$replyTo]['children'] : [];
                $childList[] = &$row;
                $byId[$replyTo]['children'] = $childList;
            }
        }
        unset($row);

        // Flatten back to a list with depth
        $result = [];
        $this->flattenTree($roots, 0, $result);
        return $result;
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<mixed> $nodes
     * @param list<array<string,mixed>> $out
     */
    private function flattenTree(array &$nodes, int $depth, array &$out): void
    {
        foreach ($nodes as &$node) {
            /** @var array<string,mixed> $node */
            $node['depth']        = $depth;
            $node['has_children'] = !empty($node['children']);
            $rawChildren = $node['children'] ?? [];
            $children    = is_array($rawChildren) ? $rawChildren : [];
            unset($node['children']);
            $out[] = $node;
            if ($children !== []) {
                $this->flattenTree($children, $depth + 1, $out);
            }
        }
    }

    private function checkAntispam(string $content): ?string
    {
        foreach ($this->antispamRegex as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }
            if (preg_match((string) $rule['regex'], $content)) {
                return (string) $rule['message'];
            }
        }
        return null;
    }

/** @return Result<never> */
    private function opErr(string $operation, string $detail = ''): Result
    {
        $diagnostic = match ($operation) {
            'not_allowed'       => new CommentNotAllowedDiagnostic('astrx.comment/not_allowed', DiagnosticLevel::WARNING),
            'empty_content'     => new CommentEmptyContentDiagnostic('astrx.comment/empty_content', DiagnosticLevel::NOTICE),
            'invalid_email'     => new CommentInvalidEmailDiagnostic('astrx.comment/invalid_email', DiagnosticLevel::NOTICE),
            'reply_not_found'   => new CommentReplyNotFoundDiagnostic('astrx.comment/reply_not_found', DiagnosticLevel::NOTICE),
            'reply_wrong_page'  => new CommentReplyWrongPageDiagnostic('astrx.comment/reply_wrong_page', DiagnosticLevel::NOTICE),
            'antispam'          => new CommentAntispamDiagnostic('astrx.comment/antispam', DiagnosticLevel::NOTICE, $detail),
            'muted'             => new CommentMutedDiagnostic('astrx.comment/muted', DiagnosticLevel::NOTICE),
            'flood'             => new CommentFloodDiagnostic('astrx.comment/flood', DiagnosticLevel::NOTICE),
            'gate_denied'       => new CommentGateDeniedDiagnostic('astrx.comment/gate_denied', DiagnosticLevel::WARNING),
            'comment_not_found' => new CommentNotFoundDiagnostic('astrx.comment/not_found', DiagnosticLevel::WARNING),
            default             => new CommentNotFoundDiagnostic('astrx.comment/unknown', DiagnosticLevel::WARNING),
        };

        // The error value is a translation key the controller flashes to the
        // poster after the PRG redirect (the diagnostic alone is drained to the
        // collector and lost on redirect). For antispam it is the rule's own
        // configured message key; for everything else a comment.error.* key.
        $displayKey = match ($operation) {
            'not_allowed'       => 'comment.error.not_allowed',
            'empty_content'     => 'comment.error.empty',
            'invalid_email'     => 'comment.error.invalid_email',
            'reply_not_found'   => 'comment.error.reply_not_found',
            'reply_wrong_page'  => 'comment.error.reply_wrong_page',
            'antispam'          => $detail !== '' ? $detail : 'comment.error.antispam',
            'muted'             => 'comment.error.muted',
            'flood'             => 'comment.error.flood',
            'gate_denied'       => 'comment.error.gate_denied',
            'comment_not_found' => 'comment.error.not_found',
            default             => 'comment.error.generic',
        };
        return Result::err($displayKey, Diagnostics::of($diagnostic));
    }
}
