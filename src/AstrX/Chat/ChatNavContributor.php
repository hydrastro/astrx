<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Module\NavContributor;

/**
 * Chat's contribution to the site chrome: it registers the `chat_nav` partial
 * slot so default.html's `{{> chat_nav}}` renders the chat toolbar. The partial
 * self-hides (via `chat_nav_show`) off chat pages; this only makes the slot
 * exist. When the chat module is disabled in Modules.config.php this class is
 * never built and the slot is absent, so the toolbar vanishes and core never
 * referenced it.
 *
 * Distinct from {@see ChatNav}, which populates the toolbar's own items on chat
 * pages; this only wires the module-level slot into the shell.
 */
final class ChatNavContributor implements NavContributor
{
    /** @return array<string,mixed> */
    public function vars(): array
    {
        return ['chat_nav' => 'partials/chat_nav'];
    }
}
