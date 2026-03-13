<?php

declare(strict_types = 1);

return [
    'ContentManager' => [
        'default_template'  => 'default',
        'error_page_id'     => 'WORDING_ERROR',
        'main_page_id'      => 'WORDING_MAIN',
        'pages_lang_domain' => 'pages',
        'navbar_lang_domain'       => 'Navbar',
        'diagnostics_lang_domain'  => 'Diagnostics',

        'public_navbar_id'  => 1,

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