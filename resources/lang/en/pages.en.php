<?php
declare(strict_types=1);

/**
 * Page URL slugs — en locale.
 *
 * Every WORDING_ key corresponds to a page row with i18n=1.
 * The value is the public URL slug for this locale.
 *
 * Convention:
 *   WORDING_ prefix + i18n=1 → translated slug (this file)
 *   No prefix   + i18n=0    → raw literal slug, not translated
 *
 * Admin and developer docs use English slugs as stable canonical references.
 */
return [
    // Framework pages
    'WORDING_MAIN'      => 'main',
    'WORDING_ERROR'     => 'error',

    // User-facing pages
    'WORDING_LOGIN'     => 'login',
    'WORDING_REGISTER'  => 'register',
    'WORDING_RECOVER'   => 'recover',
    'WORDING_PROFILE'   => 'profile',
    'WORDING_SETTINGS'  => 'settings',
    'WORDING_USER_HOME' => 'user-home',
    'WORDING_USER'      => 'user',
    'WORDING_LOGOUT'    => 'logout',

    // Keyword labels
    'WORDING_MAIN_PAGE' => 'Main Page',
    'WORDING_INDEX'     => 'Index',
];