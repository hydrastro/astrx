<?php

declare(strict_types = 1);

/**
 * Mail configuration.
 * When running on the web server and sending transactional mail:
 *   - If your web server is on the SAFE node: connect to postfix on safe-net directly
 *   - If your web server is on the FRONTEND node: connect to postfix-front
 *   - If you want to send directly via Tor to the safe node, set socks5_host
 * The from_address should be a valid address on your mail domain.
 */
return [
    'Mailer' => [
        // SMTP server — use 'postfix' if web app is on safe node,
        // 'postfix-front' if on frontend node, or the .onion via socks5
        'host' => 'postfix',
        'port' => 587,
        'username' => 'noreply@YOUR_ONION_OR_FRONTEND_DOMAIN',
        'password' => 'CHANGE_ME',
        'from_address' => 'noreply@YOUR_ONION_OR_FRONTEND_DOMAIN',
        'from_name' => 'AstrX',
        'encryption' => 'tls',   // 'tls' (STARTTLS) | 'ssl' (implicit) | ''
        'timeout' => 30,

        // Set socks5_host to route through Tor (needed if SMTP host is .onion
        // and the web server is NOT on the safe node)
        'socks5_host' => '',      // e.g. 'tor-client'
        'socks5_port' => 9050,
    ],
];