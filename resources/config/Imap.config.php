<?php
declare(strict_types=1);

/**
 * IMAP client configuration.
 *
 * ImapClient settings:
 *   imap_host         IMAP server hostname (e.g. 'dovecot' in Docker, or 'mail.example.com')
 *   imap_port         993 = IMAPS (implicit TLS), 143 = IMAP + STARTTLS
 *   imap_encryption   'ssl' (implicit TLS/IMAPS), 'tls' (STARTTLS), '' (plain)
 *   imap_timeout      Connection timeout in seconds
 *   imap_socks5_host  SOCKS5 proxy host (empty = no proxy)
 *   imap_socks5_port  SOCKS5 proxy port (default 9050 for Tor)
 *
 * WebmailService settings:
 *   messages_per_page  Messages shown per folder page
 *   trash_folder       IMAP folder used as Trash
 *   sent_folder        IMAP folder used for sent messages
 *   drafts_folder      IMAP folder for drafts
 */
return [
    'ImapClient' => [
        'imap_host'        => 'dovecot',
        'imap_port'        => 993,
        'imap_encryption'  => 'ssl',
        'imap_timeout'     => 30,
        'imap_socks5_host' => '',
        'imap_socks5_port' => 9050,
    ],
    'WebmailConfig' => [
        // The domain appended to the mailbox local-part to form the full address.
        // e.g. mailbox='alice' + mail_domain='mail.example.com' → alice@mail.example.com
        'mail_domain'               => 'localhost',
        // Set to true if your IMAP server requires a full address for LOGIN
        // (e.g. 'alice@mail.example.com'). Set to false if it accepts the local-part
        // only (e.g. 'alice'). Dovecot with virtual users typically requires the full
        // address; Courier/Cyrus may accept local-part only.
        'imap_login_use_full_address' => true,
    ],
    'WebmailService' => [
        'messages_per_page' => 25,
        'trash_folder'      => 'Trash',
        'sent_folder'       => 'Sent',
        'drafts_folder'     => 'Drafts',
    ],
];