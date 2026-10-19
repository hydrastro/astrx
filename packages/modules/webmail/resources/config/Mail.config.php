<?php
declare(strict_types=1);

/**
 * Mail configuration — the Webmail module's section(s); the site's Mail.config.php overrides any key.
 */
return [
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
