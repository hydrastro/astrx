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
    'WORDING_SEARCH.label'           => 'Search',
    'WORDING_BOARD.label'            => 'Boards',
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
    'WORDING_ADMIN_THEMES.label'     => 'Themes',
    'WORDING_ADMIN_NAVBAR.label'     => 'Navbar',
    'WORDING_ADMIN_PAGES.label'      => 'Pages',
    'WORDING_ADMIN_NOTES.label'      => 'Notes',
    'WORDING_ADMIN_SEARCH.label'     => 'Search index',

    // Admin navbar — configuration
    'WORDING_ADMIN_CONFIG_SYSTEM.label'   => 'System',
    'WORDING_ADMIN_CONFIG_ACCESS.label'   => 'Access',
    'WORDING_ADMIN_CONFIG_CONTENT.label'  => 'Content',
    'WORDING_ADMIN_CONFIG_COMMENTS.label' => 'Comments',
    'WORDING_ADMIN_CONFIG_CAPTCHA.label'  => 'Captcha',
    'WORDING_ADMIN_CONFIG_USERS.label'    => 'Users',
    'WORDING_ADMIN_CONFIG_MAIL.label'     => 'Mail',
    'WORDING_ADMIN_CONFIG_WEBMAIL.label'  => 'Webmail / IMAP',
    'WORDING_ADMIN_AUDIT_LOG.label'       => 'Audit Log',
    'WORDING_WEBMAIL.label'               => 'Webmail',
    'WORDING_CHAT.label'                  => 'Chat',
    'WORDING_ADMIN_CONFIG_CHAT.label'     => 'Chat',
    'WORDING_ADMIN_CONFIG_IMAGEBOARD.label' => 'Imageboard',
    'WORDING_ADMIN_BOARDS.label'          => 'Boards',
    'WORDING_CONTENT.label'               => 'Pages',
    'WORDING_ADMIN_CONTENT.label'         => 'Content',
    'WORDING_ADMIN_INVITES.label'         => 'Invitations',
    'WORDING_ADMIN_MEDIA.label'           => 'Media',
    'WORDING_ADMIN_LANGUAGE.label'        => 'Languages',
    'WORDING_ADMIN_TRAP.label'            => 'Bot trap',

    // Public navbar — transparency / trust pages (each an optional module)
    'WORDING_CANARY.label'                => 'Canary',
    'WORDING_DOWNLOADS.label'             => 'Downloads',
    'WORDING_MIRRORS.label'               => 'Mirrors',
    'WORDING_TIPLINE.label'               => 'Tip Line',

    // User navbar — account security
    'WORDING_TWOFACTOR.label'             => 'Two-Factor Auth',

    // Admin navbar — transparency editors + safety/retention tools
    'WORDING_ADMIN_CANARY.label'          => 'Canary',
    'WORDING_ADMIN_DOWNLOADS.label'       => 'Downloads',
    'WORDING_ADMIN_MIRRORS.label'         => 'Mirrors',
    'WORDING_ADMIN_TIPLINE.label'         => 'Tip Line',
    'WORDING_ADMIN_RETENTION.label'       => 'Retention',
    'WORDING_ADMIN_PANIC.label'           => 'Panic',
];