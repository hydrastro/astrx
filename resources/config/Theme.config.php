<?php
declare(strict_types=1);

/**
 * Theme configuration.
 *
 * The global active theme is the name of a directory under
 * resources/template/themes/. Authenticated users may override this
 * with their own preference (stored in user.theme); guests always
 * see the global theme.
 *
 * Changing 'theme' takes effect on the next request — no rebuild needed.
 * The framework discovers themes by scanning the themes/ directory
 * for any folder that contains both style.css and theme.config.php.
 */
return [
    'Theme' => [
        // Default theme name. Must match a folder name under
        // resources/template/themes/. Falls back to 'default' if missing.
        'theme' => getenv('ASTRX_THEME') ?: 'default',

        // If true, logged-in users can pick their own theme on their
        // settings page and that overrides the global one for them.
        // If false, everyone sees the global theme.
        'allow_user_override' => true,
    ],
];
