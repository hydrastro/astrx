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

    /**
     * The binary rate-limit / mute key for a GUEST chat ident: the 32-hex-char
     * presence token packed to 16 bytes, so it fits the shared VARBINARY(16)
     * `ip` column that keys the flood/mute lookups WITHOUT colliding with the
     * single Tor exit IP every guest shares. Members key on their user id, not
     * this. Returns null for a token that is not 32 hex chars, letting the caller
     * fall back to the raw IP rather than key on a malformed value.
     */
    public static function guestRateKey(string $ident): ?string
    {
        if (strlen($ident) !== 32 || !ctype_xdigit($ident)) {
            return null;
        }
        $packed = @hex2bin($ident);
        return $packed !== false ? $packed : null;
    }
}
