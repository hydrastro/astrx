<?php
declare(strict_types=1);

namespace AstrX\User;

/**
 * Controls how a user account and its associated content are handled on deletion.
 *
 * full_delete    — Hard delete: the user row is removed and all comments cascade.
 *                  No ghost account involvement. Thread structure is destroyed.
 *
 * hard_redact    — PII is wiped; comments are reassigned to the ghost account so
 *                  thread structure is preserved. A tombstone row remains with
 *                  deletion_mode=hard_redact so auditors know a deletion occurred.
 *
 * soft_redact    — Row stays intact in the DB. The rendering layer substitutes
 *                  "[deleted]" for username/content at display time. Reversible.
 *
 * keep_visible   — Account is closed (cannot log in) but profile and content
 *                  remain fully visible. Useful for voluntary retirement.
 *
 * keep_suspended — Account is admin-disabled. Content is hidden from public but
 *                  visible to admins. Used for investigations or legal holds.
 */
enum DeletionMode: string
{
    case NONE            = 'none';
    case KEEP_VISIBLE    = 'keep_visible';
    case KEEP_SUSPENDED  = 'keep_suspended';
    case SOFT_REDACT     = 'soft_redact';
    case HARD_REDACT     = 'hard_redact';
    case FULL_DELETE     = 'full_delete';

    /** Whether this mode still leaves the user row in the database. */
    public function keepsRow(): bool
    {
        return $this !== self::FULL_DELETE;
    }

    /** Whether this mode redacts or hides content from public view. */
    public function hidesContent(): bool
    {
        return match ($this) {
            self::SOFT_REDACT, self::HARD_REDACT, self::KEEP_SUSPENDED => true,
            default => false,
        };
    }

    /** Whether the account can still log in under this mode. */
    public function allowsLogin(): bool
    {
        return $this === self::NONE;
    }
}
