<?php
declare(strict_types=1);

/**
 * Admin — en: the Webmail module's keys (merged into the site's Admin catalog).
 */
return [
    'WORDING_ADMIN_CONFIG_WEBMAIL.title'    => 'Config — Webmail',
    'WORDING_ADMIN_CONFIG_WEBMAIL.description' => 'Edit IMAP and webmail configuration.',
    'admin.config.webmail.heading'          => 'Webmail Configuration',
    'admin.config.webmail.imap'             => 'IMAP Server',
    'admin.config.webmail.folders'          => 'Folder Names',
    'admin.config.field.imap_host'          => 'IMAP host',
    'admin.config.field.imap_port'          => 'IMAP port',
    'admin.config.field.imap_encryption'    => 'Encryption',
    'admin.config.field.imap_timeout'       => 'Timeout (s)',
    'admin.config.field.imap_socks5_host'   => 'SOCKS5 host (Tor)',
    'admin.config.field.imap_socks5_port'   => 'SOCKS5 port',
    'admin.config.field.messages_per_page'  => 'Messages per page',
    'admin.config.field.trash_folder'       => 'Trash folder name',
    'admin.config.field.mail_domain'        => 'Mail domain',
    'admin.config.field.imap_login_use_full_address' => 'Login with full address (user@domain)',
    'admin.config.field.imap_verify_ssl'    => 'Verify SSL certificate',
    'admin.config.field.mailbox_is_username' => 'Mailbox name is the username',

    'admin.config.field.sent_folder'        => 'Sent folder name',
    'admin.config.field.drafts_folder'      => 'Drafts folder name',

    // Dashboard tile (Admin\AdminDashboardSection)
    'admin.nav.config_webmail'      => 'Webmail / IMAP',
    'admin.nav.config_webmail.desc' => 'IMAP server and webmail settings.',
];
