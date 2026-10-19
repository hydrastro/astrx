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
 *
 * @phpstan-type ImageMeta array{token:string,full_name:string,thumb_name:string,mime:string,size:int,width:int,height:int,thumb_w:int,thumb_h:int,ahash:int,sha256:string,orig_name:string,spoiler:bool}
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

        $body = trim($s->body);
        $cen  = $this->censor($body);
        if ($cen['blocked']) {
            return $this->fail('censored');
        }
        $body     = $cen['text'];
        $hasImage = $s->images !== [];
        if ($body === '' && !$hasImage) {
            return $this->fail('empty');
        }
        if (mb_strlen($body) > $this->intOf($board, 'max_post_len')) {
            return $this->fail('too_long');
        }

        $html = $this->renderer->render($body, $this->boolOf($board, 'bbcode'));

        $metasR = $this->storeImages($s);
        if (!$metasR->isOk()) {
            return Result::err(null, $metasR->diagnostics());
        }
        $metas = $metasR->unwrap();

        $noR = $this->boards->nextPostNo($boardId);
        if (!$noR->isOk()) {
            foreach ($metas as $m) { $this->images->discard($m); }
            return Result::err(null, $noR->diagnostics());
        }

        $tR = $this->threads->create($boardId, mb_substr(trim($s->subject), 0, 255));
        if (!$tR->isOk()) {
            foreach ($metas as $m) { $this->images->discard($m); }
            return Result::err(null, $tR->diagnostics());
        }
        $tid = $tR->unwrap();

        $pR = $this->posts->create($this->draft($tid, $boardId, $noR->unwrap(), true, $body, $html, $s, $board));
        if (!$pR->isOk()) {
            foreach ($metas as $m) { $this->images->discard($m); }
            $this->threads->delete($tid);
            return Result::err(null, $pR->diagnostics());
        }

        $stored = $this->persistImages($pR->unwrap(), $metas);
        if ($stored > 0) {
            $this->threads->adjustCounts($tid, 0, $stored);
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

        $body = trim($s->body);
        $cen  = $this->censor($body);
        if ($cen['blocked']) {
            return $this->fail('censored');
        }
        $body     = $cen['text'];
        $hasImage = $s->images !== [];
        if ($body === '' && !$hasImage) {
            return $this->fail('empty');
        }
        if (mb_strlen($body) > $this->intOf($board, 'max_post_len')) {
            return $this->fail('too_long');
        }

        $html = $this->renderer->render($body, $this->boolOf($board, 'bbcode'));

        $metasR = $this->storeImages($s);
        if (!$metasR->isOk()) {
            return Result::err(null, $metasR->diagnostics());
        }
        $metas = $metasR->unwrap();

        $noR = $this->boards->nextPostNo($boardId);
        if (!$noR->isOk()) {
            foreach ($metas as $m) { $this->images->discard($m); }
            return Result::err(null, $noR->diagnostics());
        }

        $pR = $this->posts->create($this->draft($threadId, $boardId, $noR->unwrap(), false, $body, $html, $s, $board));
        if (!$pR->isOk()) {
            foreach ($metas as $m) { $this->images->discard($m); }
            return Result::err(null, $pR->diagnostics());
        }
        $pid = $pR->unwrap();

        $stored = $this->persistImages($pid, $metas);
        $this->threads->adjustCounts($threadId, 1, $stored);

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
        $forcedAnon = $this->boolOf($board, 'forced_anon');

        // Tripcode: a '#' in the name splits the display name from a trip secret.
        // The trip is a stable, non-reversible token from the secret + a site
        // salt (so trips are unique to this deployment). forced-anon strips both.
        $rawName = $forcedAnon ? '' : mb_substr(trim($s->name), 0, 64);
        $name    = $rawName;
        $trip    = '';
        if (!$forcedAnon && str_contains($rawName, '#')) {
            [$before, $secret] = explode('#', $rawName, 2);
            $name   = trim($before);
            $secret = trim($secret);
            if ($secret !== '') {
                $trip = $this->tripcode($secret);
            }
        }

        // Capcode: the controller passes a validated staff token ('admin'|'mod')
        // when a staff poster opts to show their role; forced-anon strips it.
        $capcode = $forcedAnon ? '' : $s->capcode;

        // Per-thread poster ID: a short hash of the poster's per-browser token +
        // this thread, so anons in one thread are distinguishable without any
        // account or stored IP (Tor-safe). Only when the board enables it.
        $posterId = '';
        if ($this->boolOf($board, 'poster_ids') && $s->identityToken !== '') {
            $posterId = substr(
                hash('sha256', $s->identityToken . ':' . $threadId . ':' . $this->config->posterIdSalt()),
                0, 8
            );
        }

        // User-selected flag: honoured only in 'user' flags mode with a code in
        // the configured set (no geo-IP — self-selected, Tor-safe).
        $flag = '';
        if ($this->strOf($board, 'flags_mode') === 'user' && $s->flagCode !== ''
            && array_key_exists($s->flagCode, $this->config->boardFlags())) {
            $flag = $s->flagCode;
        }

        $dpw = $s->deletePassword !== '' ? password_hash($s->deletePassword, PASSWORD_DEFAULT) : '';
        return new PostDraft(
            threadId:     $threadId,
            boardId:      $boardId,
            no:           $no,
            isOp:         $isOp,
            bodyRaw:      $body,
            bodyHtml:     $html,
            name:         mb_substr($name, 0, 64),
            tripcode:     $trip,
            capcode:      $capcode,
            posterId:     $posterId,
            flagCode:     $flag,
            subject:      mb_substr(trim($s->subject), 0, 255),
            hexUserId:    $s->hexUserId,
            packedIp:     $s->packedIp,
            posterKey:    $s->posterKey,
            deletePwHash: $dpw,
            sage:         $s->sage,
        );
    }

    /** Stable, non-reversible tripcode token from a secret + the site salt. */
    private function tripcode(string $secret): string
    {
        $h = hash('sha256', $secret . "\x00" . $this->config->tripcodeSalt(), true);
        return substr(strtr(base64_encode($h), '+/=', 'ABC'), 0, 10);
    }

    /**
     * Apply the board word censor. 'block' mode rejects a post that matches any
     * term; 'replace' mode swaps matched terms. Terms are literals (preg_quote),
     * never user-supplied regex. Mirrors Chat\WordCensor for board bodies.
     *
     * @return array{blocked:bool, text:string}
     */
    private function censor(string $text): array
    {
        $words = $this->config->censorWords();
        if ($words === []) {
            return ['blocked' => false, 'text' => $text];
        }
        $block = $this->config->censorMode() === 'block';
        $repl  = $this->config->censorReplacement();
        $out   = $text;
        foreach ($words as $word) {
            $pattern = '/' . preg_quote($word, '/') . '/iu';
            if ($block) {
                if (preg_match($pattern, $out) === 1) {
                    return ['blocked' => true, 'text' => $text];
                }
                continue;
            }
            $replaced = preg_replace($pattern, $repl, $out);
            if (is_string($replaced)) {
                $out = $replaced;
            }
        }
        return ['blocked' => false, 'text' => $out];
    }

    /**
     * Store every submitted file (capped at the per-post limit). On any single
     * failure the already-stored files are discarded and the error returned.
     *
     * @return Result<list<ImageMeta>>
     */
    private function storeImages(SubmittedPost $s): Result
    {
        $metas = [];
        $max   = max(1, $this->config->maxFilesPerPost());
        foreach ($s->images as $file) {
            if (count($metas) >= $max) {
                break;
            }
            if ($file->hasError()) {
                continue;
            }
            $iR = $this->images->store($file, $s->spoiler);
            if (!$iR->isOk()) {
                foreach ($metas as $m) { $this->images->discard($m); }
                return Result::err(null, $iR->diagnostics());
            }
            $metas[] = $iR->unwrap();
        }
        return Result::ok($metas);
    }

    /**
     * Persist the stored files against a saved post, discarding any that fail.
     * Returns how many rows were written (for the thread image counter).
     *
     * @param list<ImageMeta> $metas
     */
    private function persistImages(int $postId, array $metas): int
    {
        $stored = 0;
        foreach ($metas as $meta) {
            if ($this->images->persist($postId, $meta)->isOk()) {
                $stored++;
            } else {
                $this->images->discard($meta);
            }
        }
        return $stored;
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
