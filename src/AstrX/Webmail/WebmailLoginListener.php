<?php
declare(strict_types=1);

namespace AstrX\Webmail;

use AstrX\User\LoginListener;
use AstrX\User\UserSession;

/**
 * When the mail server shares the site's accounts (mailserver_is_local), the
 * login password IS the IMAP password: keep it in the AES-encrypted session so
 * the webmail page can connect without asking for it again. Kept across a
 * second-factor step too; if that step fails the session discards it.
 *
 * Otherwise nothing is kept. The /login form used to store the site password
 * unconditionally, so with a remote mail server the first webmail visit tried
 * it against IMAP, failed, and showed a wrong-password error before asking for
 * the real one — and the cleartext password sat in the session for nothing.
 */
final class WebmailLoginListener implements LoginListener
{
    public function __construct(
        private readonly WebmailService $webmail,
        private readonly UserSession    $session,
    ) {}

    public function passwordAccepted(string $password, bool $secondFactorPending): void
    {
        if ($this->webmail->mailserverIsLocal() && $password !== '') {
            $this->session->storeImapPassword($password);
        }
    }
}
