<?php
declare(strict_types=1);

namespace AstrX\Comment;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Auth\Policy\CommentPolicy;
use AstrX\Comment\Diagnostic\CommentDiagnostic;
use AstrX\Config\InjectConfig;
use AstrX\Result\Diagnostics;
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
    ): Result {
        $offset = ($pageNum - 1) * $this->commentsPerPage;
        $result = $this->repo->fetchForPage(
            $pageId, $descending, $this->commentsPerPage, $offset
        );
        if (!$result->isOk()) {
            return $result;
        }
        return Result::ok($this->assembleTree($result->unwrap()));
    }

    /** @return Result<int> */
    public function countForPage(int $pageId): Result
    {
        return $this->repo->countForPage($pageId);
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
            $name = ($name !== null && trim($name) !== '') ? trim($name) : 'Anonymous';
        }

        // Reply validation
        if ($replyTo !== null) {
            $parentResult = $this->repo->findById($replyTo);
            if (!$parentResult->isOk() || $parentResult->unwrap() === null) {
                return $this->opErr('reply_not_found');
            }
            $parent = $parentResult->unwrap();
            if ((int) $parent['page_id'] !== $pageId) {
                return $this->opErr('reply_wrong_page');
            }
        }

        // Antispam regex
        $spamErr = $this->checkAntispam($content);
        if ($spamErr !== null) {
            return $this->opErr('antispam', $spamErr);
        }

        // Build packed IP
        $ip = null;
        if ($remoteIp !== null && filter_var($remoteIp, FILTER_VALIDATE_IP)) {
            $packed = inet_pton($remoteIp);
            $ip = $packed !== false ? $packed : null;
        }

        $hexUserId = $this->session->isLoggedIn() ? $this->session->userId() : null;

        return $this->repo->create(
            $pageId, $hexUserId,
            $hexUserId !== null ? null : $name,
            $hexUserId !== null ? null : $email,
            $content, $replyTo, $ip,
        );
    }

    // -------------------------------------------------------------------------
    // Moderation (used by admin controller and future public report flow)
    // -------------------------------------------------------------------------

    /** @return Result<true> */
    public function hide(int $commentId): Result
    {
        if ($this->gate->cannot(Permission::COMMENT_HIDE_ANY)) {
            return $this->opErr('gate_denied');
        }
        return $this->repo->setHidden($commentId, true);
    }

    /** @return Result<true> */
    public function unhide(int $commentId): Result
    {
        if ($this->gate->cannot(Permission::COMMENT_HIDE_ANY)) {
            return $this->opErr('gate_denied');
        }
        return $this->repo->setHidden($commentId, false);
    }

    /** @return Result<true> */
    public function delete(int $commentId): Result
    {
        if ($this->gate->cannot(Permission::COMMENT_DELETE_ANY)) {
            return $this->opErr('gate_denied');
        }
        return $this->repo->delete($commentId);
    }

    /** @return Result<true> */
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
            $byId[(int) $row['id']] = $row + ['depth' => 0, 'children' => []];
        }

        $roots = [];
        foreach ($byId as $id => &$row) {
            $replyTo = $row['reply_to'] !== null ? (int) $row['reply_to'] : null;
            if ($replyTo === null || !isset($byId[$replyTo])) {
                $roots[] = &$row;
            } else {
                $byId[$replyTo]['children'][] = &$row;
            }
        }
        unset($row);

        // Flatten back to a list with depth
        $result = [];
        $this->flattenTree($roots, 0, $result);
        return $result;
    }

    // -------------------------------------------------------------------------

    /** @param list<array<string,mixed>> $nodes */
    private function flattenTree(array &$nodes, int $depth, array &$out): void
    {
        foreach ($nodes as &$node) {
            $node['depth']        = $depth;
            $node['has_children'] = !empty($node['children']);
            $children             = $node['children'];
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
                return (string) ($rule['message'] ?? 'Comment rejected by antispam filter.');
            }
        }
        return null;
    }

    private function opErr(string $operation, string $detail = ''): Result
    {
        return Result::err(null, Diagnostics::of(new CommentDiagnostic(
            CommentDiagnostic::ID, CommentDiagnostic::LEVEL, $operation, $detail
        )));
    }
}
