<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Http\UploadedFile;

/**
 * Raw per-request submission for a new post, gathered from the POST form by
 * BoardController. PostService turns it into a PostDraft — rendering the body,
 * resolving identity (later phases), and allocating the per-board number.
 */
final class SubmittedPost
{
    public function __construct(
        public readonly string        $name           = '',
        public readonly string        $subject        = '',
        public readonly string        $body           = '',
        public readonly bool          $sage           = false,
        public readonly string        $deletePassword = '',
        public readonly ?UploadedFile $image          = null,
        public readonly ?string       $packedIp       = null,   // inet_pton() bytes
        public readonly ?string       $hexUserId      = null,   // authenticated poster
        public readonly string        $posterKey      = '',     // hashed key for poster IDs / history
        public readonly bool          $spoiler        = false,  // mark the attached image as a spoiler
    ) {}
}
