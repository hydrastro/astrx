<?php
declare(strict_types=1);

return [
    'Mailer' => [
        'host'         => getenv('MAIL_HOST')         ?: 'localhost',
        'port'         => (int)(getenv('MAIL_PORT')   ?: 25),
        'username'     => getenv('MAIL_USER')         ?: '',
        'password'     => getenv('MAIL_PASSWORD')     ?: '',
        'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'noreply@localhost',
        'from_name'    => getenv('MAIL_FROM_NAME')    ?: 'App',
        'encryption'   => getenv('MAIL_ENCRYPTION')   ?: '',
        'timeout'      => 30,
    ],
    'ImapClient' => [
        'imap_host'       => getenv('IMAP_HOST')      ?: 'localhost',
        'imap_port'       => (int)(getenv('IMAP_PORT')?: 993),
        'imap_encryption' => getenv('IMAP_ENCRYPTION')?: 'ssl',
        'imap_timeout'    => 30,
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
