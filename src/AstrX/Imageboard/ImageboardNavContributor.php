<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Module\NavContributor;

/**
 * Imageboard's contribution to the site chrome: it registers the `board_nav`
 * partial slot so default.html's `{{> board_nav}}` renders the imageboard nav
 * stack. The partial itself self-hides (via `board_nav_show`) on non-board
 * pages; this only makes the slot exist. When the imageboard module is disabled
 * in Modules.config.php this class is never built and the slot is absent, so the
 * board nav vanishes and core never referenced it.
 */
final class ImageboardNavContributor implements NavContributor
{
    /** @return array<string,mixed> */
    public function vars(): array
    {
        return ['board_nav' => 'partials/board_nav'];
    }
}
