<?php
declare(strict_types=1);

return [
    'Mailer' => [
        'host'         => getenv('MAIL_HOST')         ?: 'localhost',
        // SOCKS5 proxy for .onion / Tor delivery — leave empty to disable.
        'socks5_host'  => getenv('MAIL_SOCKS5_HOST')  ?: '',
        'socks5_port'  => (int)(getenv('MAIL_SOCKS5_PORT') ?: 0),
        'port'         => (int)(getenv('MAIL_PORT')   ?: 25),
        'username'     => getenv('MAIL_USER')         ?: '',
        'password'     => getenv('MAIL_PASSWORD')     ?: '',
        'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'noreply@localhost',
        'from_name'    => getenv('MAIL_FROM_NAME')    ?: 'App',
        'encryption'   => getenv('MAIL_ENCRYPTION')   ?: '',
        'timeout'      => 30,
        // Verify the SMTP server's TLS certificate (STARTTLS + implicit TLS,
        // including over a SOCKS5 tunnel). Keep true for clearnet delivery.
        // Onion-only deployments may set this false because Tor already
        // authenticates the hidden service.
        'smtp_verify_ssl' => (getenv('MAIL_VERIFY_SSL') ?: 'true') === 'true',
    ],
    'ImapClient' => [
        'imap_host'       => getenv('IMAP_HOST')      ?: 'localhost',
        // SOCKS5 proxy for .onion / Tor IMAP — leave empty to disable.
        'imap_socks5_host'=> getenv('IMAP_SOCKS5_HOST')?: '',
        'imap_socks5_port'=> (int)(getenv('IMAP_SOCKS5_PORT') ?: 0),
        'imap_port'       => (int)(getenv('IMAP_PORT')?: 993),
        'imap_encryption' => getenv('IMAP_ENCRYPTION')?: 'ssl',
        'imap_timeout'    => 30,
        // Verify the IMAP server's TLS certificate (implicit TLS, STARTTLS, and
        // over a SOCKS5 tunnel). Keep true for clearnet. Onion-only deployments
        // where Tor already authenticates the hidden service may set this false.
        'imap_verify_ssl' => (getenv('IMAP_VERIFY_SSL') ?: 'true') === 'true',
    ],
    'WebmailService' => [
        'mail_domain'                 => getenv('MAIL_DOMAIN')        ?: 'localhost',
        'imap_login_use_full_address' => true,
        'mailbox_is_username'         => false,
        'mailserver_is_local'         => (getenv('MAIL_LOCAL') ?: 'false') === 'true',
        'messages_per_page'           => 25,
        'trash_folder'                => 'Trash',
        'sent_folder'                 => 'Sent',
        'drafts_folder'               => 'Drafts',
    ],
];
