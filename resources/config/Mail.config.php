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
        // Trust a bare "* PREAUTH" greeting as already-authenticated (skips LOGIN).
        // Default false — an unauthenticated/MITM'd server could otherwise assert
        // PREAUTH to skip credentials. Only enable for a trusted local IMAP.
        'imap_allow_preauth' => (getenv('IMAP_ALLOW_PREAUTH') ?: 'false') === 'true',
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
    // AstrX\Mail\MailboxManager. This section was missing from the shipped file
    // even though the class declares #[InjectConfig] setters for all three keys
    // and the admin Mail page writes them here: Config::applyConfigToInstance()
    // skips a setter whose key is absent, so on a fresh install every setter
    // silently no-op'd and MailboxManager built addresses as "user@" (empty
    // domain) until an admin happened to open the Mail page and press Save.
    // Defaults match the class's own field defaults, so declaring them changes
    // nothing until the operator sets the env vars or edits this file.
    'MailboxManager' => [
        // Domain appended to a username to form its mailbox address.
        'mailbox_domain' => getenv('MAILBOX_DOMAIN') ?: '',
        // Base URL of the provisioning API ('' disables remote provisioning).
        'mailapi_url'    => getenv('MAILAPI_URL')    ?: '',
        // Shared secret for that API.
        'mailapi_secret' => getenv('MAILAPI_SECRET') ?: '',
    ],
];
