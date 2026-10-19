<?php
declare(strict_types=1);

namespace AstrX\User;

/**
 * Told when a visitor has just proved their password at a login form.
 *
 * A module extension point (manifest `extensions`, see docs/MODULES.md): the
 * login controllers call every ENABLED module's listener and name none of
 * them. Webmail uses it to keep the password for IMAP when the mail server
 * shares the site's accounts.
 *
 * Called once per successful password check, BEFORE a second factor is asked
 * for when one is enabled ($secondFactorPending) — the session is not
 * authenticated yet then, and a listener must not treat it as if it were. If
 * the second factor then fails, the session's login state is discarded with
 * everything a listener stored in it.
 */
interface LoginListener
{
    public function passwordAccepted(string $password, bool $secondFactorPending): void;
}
