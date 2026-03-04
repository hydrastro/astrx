<?php
declare(strict_types=1);

return [
    'PDO' => [
        'db_type' => 'mysql',
        'db_host' => 'mysql',
        'db_name' => 'content_manager',
        'db_username' => 'user',
        'db_password' => 'password',

        'emulate_prepares' => false,
        'errmode_exception' => true,
        'default_fetch_assoc' => true,
    ],
];