<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Imageboard\Diagnostic\ImageboardPostDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;

/**
 * The post write-path: turn a SubmittedPost into a stored thread or reply,
 * handling body rendering, image storage, per-board numbering, counters, bump,
 * and lifecycle pruning. Identity (tripcodes/flags/poster-IDs), captcha, flood
 * and managed filters layer on in later phases — this is the core create path.
 */
final class PostService
{
    public function __construct(
        private readonly BoardRepository  $boards,
        private readonly ThreadRepository $threads,
        private readonly PostRepository   $posts,
        private readonly ImageService     $images,
        private readonly PostRenderer     $renderer,
        private readonly ImageboardConfig $config,
    ) {}

    /**
     * Start a new thread. Returns the new thread id.
     *
     * @return Result<int>
     */
    public function createThread(int $boardId, SubmittedPost $s): Result
    {
        $bR = $this->boards->byId($boardId);
        if (!$bR->isOk()) {
            return Result::err(null, $bR->diagnostics());
        }
        $board = $bR->unwrap();
        if ($board === null) {
            return $this->fail('no_board');
        }

        $body     = trim($s->body);
        $hasImage = $s->image !== null && !$s->image->hasError();
        if ($body === '' && !$hasImage) {
            return $this->fail('empty');
        }
        if (mb_strlen($body) > $this->intOf($board, 'max_post_len')) {
            return $this->fail('too_long');
        }

        $html = $this->renderer->render($body, $this->boolOf($board, 'bbcode'));

        $meta = null;
        if ($hasImage && $s->image !== null) {
            $iR = $this->images->store($s->image, false);
            if (!$iR->isOk()) {
                return Result::err(null, $iR->diagnostics());
            }
            $meta = $iR->unwrap();
        }

        $noR = $this->boards->nextPostNo($boardId);
        if (!$noR->isOk()) {
            if ($meta !== null) { $this->images->discard($meta); }
            return Result::err(null, $noR->diagnostics());
        }

        $tR = $this->threads->create($boardId, mb_substr(trim($s->subject), 0, 255));
        if (!$tR->isOk()) {
            if ($meta !== null) { $this->images->discard($meta); }
            return Result::err(null, $tR->diagnostics());
        }
        $tid = $tR->unwrap();

        $pR = $this->posts->create($this->draft($tid, $boardId, $noR->unwrap(), true, $body, $html, $s, $board));
        if (!$pR->isOk()) {
            if ($meta !== null) { $this->images->discard($meta); }
            $this->threads->delete($tid);
            return Result::err(null, $pR->diagnostics());
        }

        if ($meta !== null) {
            $persistR = $this->images->persist($pR->unwrap(), $meta);
            if ($persistR->isOk()) {
                $this->threads->adjustCounts($tid, 0, 1);
            } else {
                // Image row failed to save: drop the orphaned files and leave the
                // post text-only rather than bumping a phantom image count.
                $this->images->discard($meta);
            }
        }
        $this->prune($boardId, $board);
        return Result::ok($tid);
    }

    /**
     * Reply to a thread. Returns the new post id.
     *
     * @return Result<int>
     */
    public function reply(int $threadId, SubmittedPost $s): Result
    {
        $tR = $this->threads->byId($threadId);
        if (!$tR->isOk()) {
            return Result::err(null, $tR->diagnostics());
        }
        $thread = $tR->unwrap();
        if ($thread === null) {
            return $this->fail('no_thread');
        }
        if ($this->boolOf($thread, 'locked')) {
            return $this->fail('locked');
        }

        $boardId = $this->intOf($thread, 'board_id');
        $bR = $this->boards->byId($boardId);
        if (!$bR->isOk()) {
            return Result::err(null, $bR->diagnostics());
        }
        $board = $bR->unwrap();
        if ($board === null) {
            return $this->fail('no_board');
        }
        // A deactivated board is reachable here only via a stale thread id
        // (bySlug hides it); byId does not filter active, so enforce it so no
        // reply lands in a closed board.
        if (!$this->boolOf($board, 'active')) {
            return $this->fail('no_board');
        }

        // Thread-size cap: once a thread reaches its reply limit it auto-locks
        // and further replies are rejected — this is what bounds the cost of the
        // thread view. Per-board max_replies overrides the global default;
        // 0 on both means unlimited.
        $maxReplies = $this->intOf($board, 'max_replies');
        if ($maxReplies <= 0) {
            $maxReplies = $this->config->defaultMaxReplies();
        }
        if ($maxReplies > 0 && $this->intOf($thread, 'reply_count') >= $maxReplies) {
            $this->threads->lock($threadId);
            return $this->fail('thread_full');
        }

        $body     = trim($s->body);
        $hasImage = $s->image !== null && !$s->image->hasError();
        if ($body === '' && !$hasImage) {
            return $this->fail('empty');
        }
        if (mb_strlen($body) > $this->intOf($board, 'max_post_len')) {
            return $this->fail('too_long');
        }

        $html = $this->renderer->render($body, $this->boolOf($board, 'bbcode'));

        $meta = null;
        if ($hasImage && $s->image !== null) {
            $iR = $this->images->store($s->image, false);
            if (!$iR->isOk()) {
                return Result::err(null, $iR->diagnostics());
            }
            $meta = $iR->unwrap();
        }

        $noR = $this->boards->nextPostNo($boardId);
        if (!$noR->isOk()) {
            if ($meta !== null) { $this->images->discard($meta); }
            return Result::err(null, $noR->diagnostics());
        }

        $pR = $this->posts->create($this->draft($threadId, $boardId, $noR->unwrap(), false, $body, $html, $s, $board));
        if (!$pR->isOk()) {
            if ($meta !== null) { $this->images->discard($meta); }
            return Result::err(null, $pR->diagnostics());
        }
        $pid = $pR->unwrap();

        $imageStored = false;
        if ($meta !== null) {
            $persistR = $this->images->persist($pid, $meta);
            if ($persistR->isOk()) {
                $imageStored = true;
            } else {
                $this->images->discard($meta);
            }
        }
        $this->threads->adjustCounts($threadId, 1, $imageStored ? 1 : 0);

        // Bump unless saged, autosaged, or already at the bump limit.
        $replyCount = $this->intOf($thread, 'reply_count');
        $bumpLimit  = $this->intOf($board, 'bump_limit');
        if (!$s->sage && !$this->boolOf($thread, 'autosage') && ($replyCount + 1) <= $bumpLimit) {
            $this->threads->touchBump($threadId);
        }
        return Result::ok($pid);
    }

    /** @param array<string,mixed> $board */
    private function draft(int $threadId, int $boardId, int $no, bool $isOp, string $body, string $html, SubmittedPost $s, array $board): PostDraft
    {
        $name = $this->boolOf($board, 'forced_anon') ? '' : mb_substr(trim($s->name), 0, 64);
        $dpw  = $s->deletePassword !== '' ? password_hash($s->deletePassword, PASSWORD_DEFAULT) : '';
        return new PostDraft(
            threadId:     $threadId,
            boardId:      $boardId,
            no:           $no,
            isOp:         $isOp,
            bodyRaw:      $body,
            bodyHtml:     $html,
            name:         $name,
            subject:      mb_substr(trim($s->subject), 0, 255),
            hexUserId:    $s->hexUserId,
            packedIp:     $s->packedIp,
            posterKey:    $s->posterKey,
            deletePwHash: $dpw,
            sage:         $s->sage,
        );
    }

    /** @param array<string,mixed> $board */
    private function prune(int $boardId, array $board): void
    {
        $lifecycle = $this->strOf($board, 'lifecycle');
        if ($lifecycle === 'persistent') {
            return;
        }
        $limit = $this->intOf($board, 'thread_limit');
        for ($guard = 0; $guard < 100; $guard++) {
            $cR = $this->threads->countActive($boardId);
            if (!$cR->isOk() || $cR->unwrap() <= $limit) {
                return;
            }
            $oR = $this->threads->oldestPrunable($boardId);
            if (!$oR->isOk()) {
                return;
            }
            $old = $oR->unwrap();
            if ($old === null) {
                return;
            }
            if ($lifecycle === 'archive') {
                $this->threads->archive($old);
            } else {
                $this->threads->delete($old);
            }
        }
    }

    /** @param array<string,mixed> $a */
    private function intOf(array $a, string $k): int
    {
        $v = $a[$k] ?? null;
        return is_numeric($v) ? (int) $v : 0;
    }

    /** @param array<string,mixed> $a */
    private function boolOf(array $a, string $k): bool
    {
        return $this->intOf($a, $k) === 1;
    }

    /** @param array<string,mixed> $a */
    private function strOf(array $a, string $k): string
    {
        $v = $a[$k] ?? null;
        return is_string($v) ? $v : '';
    }

    /** @return Result<never> */
    private function fail(string $slug): Result
    {
        return Result::err(null, Diagnostics::of(new ImageboardPostDiagnostic(
            'astrx.imageboard/' . $slug, DiagnosticLevel::NOTICE
        )));
    }
}
