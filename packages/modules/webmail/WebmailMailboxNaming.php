<?php
declare(strict_types=1);

namespace AstrX\Webmail;

use AstrX\Config\ConfigDomain;
use AstrX\Config\InjectConfig;
use AstrX\User\MailboxNaming;

/**
 * Tells the registration form whether a new account's mailbox is its username
 * (`WebmailService.mailbox_is_username`, the same key WebmailService builds
 * IMAP logins and From addresses from), without building the IMAP client.
 */
#[ConfigDomain('WebmailService', file: 'Mail')]
final class WebmailMailboxNaming implements MailboxNaming
{
    private bool $mailboxIsUsername = false;

    #[InjectConfig('mailbox_is_username')]
    public function setMailboxIsUsername(bool $v): void
    {
        $this->mailboxIsUsername = $v;
    }

    public function mailboxIsUsername(): bool
    {
        return $this->mailboxIsUsername;
    }
}
