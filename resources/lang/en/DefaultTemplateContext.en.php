<?php
declare(strict_types=1);

/**
 * Shared UI strings — en locale.
 *
 * Contains ONLY strings used by DefaultTemplateContext itself on every page.
 * Page-specific strings (title, description, keywords, controller strings)
 * belong in the page's own file: lang/en/{PageFileName}.php
 *
 * user.nav.* keys live here (not in User.en.php) because DefaultTemplateContext
 * calls t() on these on EVERY page request — they must always be loaded.
 */
return [
    'generated_in' => 'Generated in:',
    'go_top'       => 'Go top',

    // User nav — shown in the header on every page
    'user.nav.guest'    => 'Login',
    'user.nav.home'     => 'Home',
    'user.nav.profile'  => 'Profile',
    'user.nav.settings' => 'Settings',
    'user.nav.logout'   => 'Logout',
]
    // Admin nav — shown when the user is an admin
    'admin.nav.home'     => 'Dashboard',
    'admin.nav.news'     => 'News',
    'admin.nav.comments' => 'Comments',
    'admin.nav.users'    => 'Users',
    'admin.nav.banlist'  => 'Banlist',
    'admin.nav.navbar'   => 'Navbar',
    'admin.nav.pages'    => 'Pages',
    'admin.nav.notes'    => 'Notes',
];
