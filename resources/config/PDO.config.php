<?php
declare(strict_types=1);

return [
    'PDO' => [
        'db_type'     => 'mysql',
        'db_host'     => getenv('DB_HOST')     ?: 'mariadb',
        'db_port'     => (int)(getenv('DB_PORT')?: 3306),
        'db_name'     => getenv('DB_NAME')     ?: 'blackhost',
        'db_username' => getenv('DB_USER')     ?: 'user',
        'db_password' => getenv('DB_PASSWORD') ?: 'password',

        'emulate_prepares'    => false,
        'errmode_exception'   => true,
        'default_fetch_assoc' => true,
    ],
];
