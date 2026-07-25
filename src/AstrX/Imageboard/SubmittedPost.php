<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Http\UploadedFile;

/**
 * Raw per-request submission for a new post, gathered from the POST form by
 * BoardController. PostService turns it into a PostDraft — rendering the body,
 * resolving identity (tripcode/capcode/poster-ID/flag), and allocating the
 * per-board number.
 *
 * A post may carry several files (item: multiple files per post); `images` is
 * the ordered list of validated uploads. `spoiler` marks every file on the post
 * as a spoiler (one checkbox for the post, matching the classic UX).
 */
final class SubmittedPost
{
    /** @param list<UploadedFile> $images */
    public function __construct(
        public readonly string  $name           = '',
        public readonly string  $subject        = '',
        public readonly string  $body           = '',
        public readonly bool    $sage           = false,
        public readonly string  $deletePassword = '',
        public readonly array   $images         = [],     // ordered validated uploads
        public readonly ?string $packedIp       = null,   // inet_pton() bytes
        public readonly ?string $hexUserId      = null,   // authenticated poster
        public readonly string  $posterKey      = '',     // hashed key for poster IDs / history
        public readonly bool    $spoiler        = false,  // mark the attached files as spoilers
        public readonly string  $flagCode       = '',     // user-selected flag code (flags_mode=user)
        public readonly string  $capcode        = '',     // staff role token ('admin'|'mod'), when the poster opts in
        public readonly string  $identityToken  = '',     // per-browser token → per-thread poster ID (Tor-safe)
    ) {}
}
