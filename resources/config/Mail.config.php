<?php
declare(strict_types=1);

return [
    'Mailer' => [
        'host' => 'postfix',
        'port' => 587,
        'username' => 'noreply@YOUR_ONION_OR_FRONTEND_DOMAIN',
        'password' => 'CHANGE_ME',
        'from_address' => 'noreply@lel.com',
        'from_name' => 'AstrXXOAX',
        'encryption' => 'tls',
        'timeout' => 30,
        'socks5_host' => '',
        'socks5_port' => 9050,
    ],
];
