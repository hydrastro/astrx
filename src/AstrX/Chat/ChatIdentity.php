<?php
declare(strict_types=1);

namespace AstrX\Chat;

/**
 * The identity of a chat participant for the duration of a request.
 *
 * `ident` is the stable 32-char key used everywhere (presence, PMs, settings,
 * moderation): a member's lowercase-hex user id, or a guest's random session
 * token. Members carry `userId` (hex) for profile linking; guests carry null.
 * `role` is a UserGroup value (0 user, 1 admin, 2 mod, 3 guest).
 */
final class ChatIdentity
{
    public function __construct(
        public readonly string  $ident,
        public readonly bool    $isMember,
        public readonly ?string $userId,
        public readonly string  $nick,
        public readonly ?string $color,
        public readonly int     $role,
    ) {}
}
