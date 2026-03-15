<?php
declare(strict_types=1);

/**
 * Navbar display labels — en locale.
 *
 * NavbarHandler::resolveName() looks up '{name}.label' first when i18n=1.
 * This prevents the URL slug (from pages.en.php) from being used as the link text.
 * e.g. 'WORDING_USER' => 'user' (slug) vs 'WORDING_USER.label' => 'User Area' (label)
 */
return [
    // Public navbar
    'WORDING_HOME.label'             => 'Home',
    'WORDING_USER.label'             => 'User Area',

    // User navbar
    'WORDING_USER_HOME.label'        => 'Home',
    'WORDING_PROFILE.label'          => 'Profile',
    'WORDING_SETTINGS.label'         => 'Settings',
    'WORDING_LOGOUT.label'           => 'Logout',

    // Admin navbar
    'WORDING_ADMIN.label'            => 'Dashboard',
    'WORDING_ADMIN_NEWS.label'       => 'News',
    'WORDING_ADMIN_COMMENTS.label'   => 'Comments',
    'WORDING_ADMIN_USERS.label'      => 'Users',
    'WORDING_ADMIN_BANLIST.label'    => 'Banlist',
    'WORDING_ADMIN_NAVBAR.label'     => 'Navbar',
    'WORDING_ADMIN_PAGES.label'      => 'Pages',
    'WORDING_ADMIN_NOTES.label'      => 'Notes',
];