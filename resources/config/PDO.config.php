<?php
declare(strict_types=1);
return [
    'PDO' => [
        'db_type'             => 'mysql',
        'db_host'             => 'mariadb',
        'db_name'             => 'content_manager',
        'db_port'             => 3306,
        // App DB account: the per-database 'user' created by src/setup/init.sql
        // (GRANT ALL on content_manager.* only) — NOT the global 'root' superuser,
        // so a SQL-injection or app compromise can't reach other databases, use
        // FILE, or GRANT. SECURITY: change this password (and init.sql + your Docker
        // root secret) before any real deployment, and don't commit real
        // credentials — prefer `git rm --cached resources/config/PDO.config.php`
        // and ship a PDO.config.php.example instead.
        'db_username'         => 'user',
        'db_password'         => 'password',
        'emulate_prepares'    => false,
        'errmode_exception'   => true,
        'default_fetch_assoc' => true,
    ],
];
