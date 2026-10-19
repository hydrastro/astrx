<?php
declare(strict_types=1);

namespace AstrX\Webmail;

use AstrX\User\UserSession;

/**
 * The IMAP password, kept for the rest of the login so the webmail page does
 * not ask for it on every visit.
 *
 * It lives among the session's login secrets (UserSession::storeSecret()),
 * which the CMS drops when the login ends, so the account code never has to
 * know webmail exists.
 */
final class WebmailCredentials
{
    private const SECRET = 'webmail.imap_password';

    /** Where releases before the login-secret store kept the password. */
    private const LEGACY_KEYS = ['_webmail_pass', '_webmail_key'];

    public function __construct(private readonly UserSession $session)
    {
        // A session from before the upgrade may still hold the old copy; it is
        // no longer read, and nothing else would ever clear it.
        foreach (self::LEGACY_KEYS as $key) {
            unset($_SESSION[$key]);
        }
    }

    public function store(string $password): void
    {
        $this->session->storeSecret(self::SECRET, $password);
    }

    /** The kept IMAP password, or '' when there is none. */
    public function password(): string
    {
        return $this->session->secret(self::SECRET);
    }

    /** Forget the kept password (the IMAP server refused it, or the user left). */
    public function clear(): void
    {
        $this->session->storeSecret(self::SECRET, '');
    }
}
