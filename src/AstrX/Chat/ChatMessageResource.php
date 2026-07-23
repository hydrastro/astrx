<?php
declare(strict_types=1);

namespace AstrX\Chat;

/**
 * Gate policy resource for chat message moderation.
 *
 * Mirrors the shape a resource-level policy expects ($user_id as a hex
 * string|null, $user_type as an int|null) so a chat policy can prevent
 * moderators from acting on messages posted by admins.
 */
final class ChatMessageResource
{
    public int $id = 0;
    public ?int $user_type = null;
    public ?string $user_id = null;
}
