<?php
declare(strict_types=1);

return [
    // ── Outbound SMTP (system mail + webmail send) ────────────────────────────
    'Mailer' => [
        'host'         => 'localhost',
        'port'         => 25,
        'username'     => '',
        'password'     => '',
        'from_address' => 'noreply@localhost',
        'from_name'    => 'AstrX',
        'encryption'   => '',
        'timeout'      => 30,
        'socks5_host'  => '',
        'socks5_port'  => 9050,
    ],

    // ── IMAP client (webmail read) ────────────────────────────────────────────
    // imap_encryption: 'ssl' = implicit TLS (port 993),
    //                  'tls' = STARTTLS (port 143),
    //                  ''    = plain, no encryption (port 143)
    'ImapClient' => [
        'imap_host'        => 'localhost',
        'imap_port'        => 993,
        'imap_encryption'  => 'ssl',
        'imap_timeout'     => 30,
        'imap_socks5_host' => '',
        'imap_socks5_port' => 9050,
        // Set to false for self-signed / private CA certificates
        'imap_verify_ssl'  => false,
    ],

    // ── Webmail service settings ──────────────────────────────────────────────
    // mail_domain: appended to the mailbox local-part to form the full address.
    //   e.g. mailbox='alice' + mail_domain='mail.example.com' → alice@mail.example.com
    // imap_login_use_full_address: true  = LOGIN alice@mail.example.com (Dovecot default)
    //                              false = LOGIN alice (some older/simpler servers)
    'WebmailService' => [
        'mail_domain'                => 'localhost',
        'imap_login_use_full_address'=> false,
        'mailbox_is_username'         => false,
        'messages_per_page'          => 25,
        'trash_folder'               => 'Trash',
        'sent_folder'                => 'Sent',
        'drafts_folder'              => 'Drafts',
    ],
];
