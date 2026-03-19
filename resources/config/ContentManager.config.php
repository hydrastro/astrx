<?php

declare(strict_types = 1);

return [
    'ContentManager' => [
        'default_template'  => 'default',
        'error_page_url_id'     => 'WORDING_ERROR',
        'main_page_id'      => 'WORDING_MAIN',
        'pages_lang_domain' => 'pages',
        'navbar_lang_domain'       => 'Navbar',
        'diagnostics_lang_domain'  => 'Diagnostics',

        'public_navbar_id'  => 1,
        'user_navbar_id'    => 2,
        'admin_navbar_id'   => 3,

        // Lang domains loaded globally (on every page load) before the page-specific domain.
        // Useful for shared string sets like all user pages sharing one 'User' lang file.
        'extra_lang_domains' => ['User'],

        'status_bar_min_level' => 2,

        'status_bar_level_classes' => [
            'DEBUG'     => 'diag-debug',
            'INFO'      => 'diag-info',
            'NOTICE'    => 'diag-notice',
            'WARNING'   => 'diag-warning',
            'ERROR'     => 'diag-error',
            'CRITICAL'  => 'diag-critical',
            'ALERT'     => 'diag-alert',
            'EMERGENCY' => 'diag-emergency',
        ],
    ]
];