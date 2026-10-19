<?php
declare(strict_types=1);

/**
 * UserSession's login secrets: what a module keeps for the length of a login
 * (webmail's IMAP password) round-trips, never sits in the session as plain
 * text, survives session-ID regeneration, and is gone once the login ends.
 *
 * Standalone: no bootstrap, no database.
 */

use AstrX\User\UserSession;
use AstrX\Webmail\WebmailCredentials;

require dirname(__DIR__) . '/src/autoload.php';

$PASS = 0;
$FAIL = 0;
function check(string $label, bool $ok): void
{
    global $PASS, $FAIL;
    $ok ? $PASS++ : $FAIL++;
    echo ($ok ? '  ok   - ' : '  FAIL - ') . $label . "\n";
}

$_SESSION = [];
$session  = new UserSession();

check('nothing kept yet', $session->secret('a') === '');

$session->storeSecret('a', 'hunter2 ünïcødé');
$session->storeSecret('b', 'other');
check('a secret round-trips', $session->secret('a') === 'hunter2 ünïcødé');
check('secrets are kept apart', $session->secret('b') === 'other');
check('no secret sits in the session as plain text',
    !str_contains(serialize($_SESSION), 'hunter2') && !str_contains(serialize($_SESSION), 'other'));

// The session id rotates; the keystream key is stored in the session itself.
$copy = $_SESSION;
$_SESSION = $copy;
check('a secret survives a session-id change', (new UserSession())->secret('a') === 'hunter2 ünïcødé');

$session->storeSecret('a', '');
check('storing an empty value forgets that secret only',
    $session->secret('a') === '' && $session->secret('b') === 'other');

$session->forgetSecrets();
check('forgetSecrets drops every secret', $session->secret('b') === '');
check('forgetSecrets leaves no secret keys behind',
    preg_grep('/secret/', array_map('strval', array_keys($_SESSION))) === []);

$session->storeSecret('c', 'x');
$session->logout();
check('logout forgets the secrets', $session->secret('c') === '');

// Webmail keeps its IMAP password there, and clears a pre-upgrade copy.
$_SESSION = ['_webmail_pass' => 'legacy', '_webmail_key' => 'legacy'];
$creds = new WebmailCredentials(new UserSession());
check('a pre-upgrade IMAP password copy is cleared',
    !isset($_SESSION['_webmail_pass']) && !isset($_SESSION['_webmail_key']));
$creds->store('imap-pass');
check('webmail credentials round-trip', $creds->password() === 'imap-pass');
(new UserSession())->forgetSecrets();
check('ending the login forgets the IMAP password', $creds->password() === '');
$creds->store('again');
$creds->clear();
check('webmail can forget its password', $creds->password() === '');

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
