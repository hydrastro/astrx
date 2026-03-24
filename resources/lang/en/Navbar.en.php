<?php
declare(strict_types=1);

/**
 * Navbar display labels — en locale.
 *
 * NavbarHandler::resolveName() looks up '{name}.label' first when i18n=1.
 * This prevents the URL slug from being used as the link text.
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

    // Admin navbar — content management
    'WORDING_ADMIN.label'            => 'Dashboard',
    'WORDING_ADMIN_NEWS.label'       => 'News',
    'WORDING_ADMIN_COMMENTS.label'   => 'Comments',
    'WORDING_ADMIN_USERS.label'      => 'Users',
    'WORDING_ADMIN_BANLIST.label'    => 'Banlist',
    'WORDING_ADMIN_NAVBAR.label'     => 'Navbar',
    'WORDING_ADMIN_PAGES.label'      => 'Pages',
    'WORDING_ADMIN_NOTES.label'      => 'Notes',

    // Admin navbar — configuration
    'WORDING_ADMIN_CONFIG_SYSTEM.label'   => 'System',
    'WORDING_ADMIN_CONFIG_ACCESS.label'   => 'Access',
    'WORDING_ADMIN_CONFIG_CONTENT.label'  => 'Content',
    'WORDING_ADMIN_CONFIG_COMMENTS.label' => 'Comments',
    'WORDING_ADMIN_CONFIG_CAPTCHA.label'  => 'Captcha',
    'WORDING_ADMIN_CONFIG_USERS.label'    => 'Users',
    'WORDING_ADMIN_CONFIG_MAIL.label'     => 'Mail',
    'WORDING_ADMIN_CONFIG_WEBMAIL.label'  => 'Webmail / IMAP',
    'WORDING_WEBMAIL.label'               => 'Webmail',
];