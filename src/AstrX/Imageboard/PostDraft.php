<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

/**
 * Immutable parameters for creating one post. Built by PostService (which
 * resolves identity, renders the body, allocates the per-board `no`) and handed
 * to PostRepository::create() — keeps the repository free of business logic and
 * gives the many post fields a typed home instead of a wide array.
 */
final class PostDraft
{
    public function __construct(
        public readonly int     $threadId,
        public readonly int     $boardId,
        public readonly int     $no,
        public readonly bool    $isOp,
        public readonly string  $bodyRaw,
        public readonly string  $bodyHtml,
        public readonly string  $name         = '',
        public readonly string  $tripcode     = '',
        public readonly string  $capcode      = '',
        public readonly string  $posterId     = '',
        public readonly string  $flagCode     = '',
        public readonly string  $subject      = '',
        public readonly ?string $hexUserId    = null,   // authenticated poster, else null
        public readonly ?string $packedIp     = null,   // inet_pton() bytes, else null
        public readonly string  $posterKey    = '',     // hashed key for poster IDs / history
        public readonly string  $deletePwHash = '',     // poster self-delete password hash
        public readonly bool    $sage         = false,
    ) {}
}
