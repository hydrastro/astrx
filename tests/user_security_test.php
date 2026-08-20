<?php
declare(strict_types=1);

/**
 * Standalone UserService / AvatarService test — NO AstrX bootstrap, SQLite.
 *
 * Covers:
 *   3. the identicon seed is an HMAC keyed with the install secret, not a
 *      reproducible sha256 of public data plus a guessed e-mail;
 *   4. the code-enforced password floor, which holds with password_regex empty;
 *   5. the login captcha no longer depends on a counter the client owns;
 *   6. a password change evicts the account's other sessions;
 *   7. the TOTP secret is encrypted at rest, and pre-existing plaintext rows
 *      still work.
 *
 * Run:  php tests/user_security_test.php
 */

namespace AstrX\Config {
    if (!\class_exists(InjectConfig::class)) {
        #[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
        final class InjectConfig
        {
            public function __construct(public readonly string $key) {}
        }
    }
}

namespace AstrX\Support {
    if (!\function_exists('AstrX\Support\configDir')) {
        function configDir(): string
        {
            return \rtrim((string) \getenv('ASTRX_TEST_CONFIG_DIR'), '/') . '/';
        }
    }
    if (!\function_exists('AstrX\Support\resourceStorageDir')) {
        function resourceStorageDir(string $configured, string $fallback): string
        {
            return $configured !== '' ? $configured : \sys_get_temp_dir() . '/' . $fallback;
        }
    }
}

namespace {

    use AstrX\Image\ImageSanitizer;
    use AstrX\Session\ServerSecret;
    use AstrX\User\AvatarService;
    use AstrX\User\UserRepository;
    use AstrX\User\UserService;
    use AstrX\User\UserSession;

    $CLASS_DIR = dirname(__DIR__) . '/src/AstrX/';
    spl_autoload_register(static function (string $class) use ($CLASS_DIR): void {
        if (strncmp($class, 'AstrX\\', 6) !== 0) { return; }
        $file = $CLASS_DIR . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) { require_once $file; }
    });

    $scratch = sys_get_temp_dir() . '/astrx_user_test_' . bin2hex(random_bytes(6));
    mkdir($scratch, 0700, true);
    putenv('ASTRX_TEST_CONFIG_DIR=' . $scratch);
    register_shutdown_function(static function () use ($scratch): void {
        foreach ((array) glob($scratch . '/{,.}*', GLOB_BRACE) as $f) {
            if (is_string($f) && is_file($f)) { @unlink($f); }
        }
        @rmdir($scratch);
    });

    $PASS = 0;
    $FAIL = 0;
    function check(string $label, bool $cond): void
    {
        global $PASS, $FAIL;
        if ($cond) { $PASS++; echo "  ok   - $label\n"; }
        else       { $FAIL++; echo "  FAIL - $label\n"; }
    }

    function secretFor(string $value): ServerSecret
    {
        $s = new ServerSecret();
        $s->setConfigured($value);
        return $s;
    }

    /** UserService wired against an in-memory SQLite user table. */
    function serviceWith(ServerSecret $secret, PDO $pdo, UserSession $session): UserService
    {
        $repo   = new UserRepository($pdo);
        $avatar = new AvatarService($repo, new ImageSanitizer(), $secret);
        return new UserService($repo, $avatar, $session, $secret);
    }

    /**
     * An in-memory `user` table the real UserRepository SQL runs against.
     * UNHEX()/UNIX_TIMESTAMP() are MySQL builtins, supplied here as SQLite
     * user-defined functions so the repository's own statements are exercised
     * verbatim rather than stubbed.
     */
    function userPdo(): PDO
    {
        $pdo = PDO::connect('sqlite::memory:');
        assert($pdo instanceof Pdo\Sqlite);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->createFunction('UNHEX', static fn (mixed $h): string|false => hex2bin((string) $h), 1);
        $pdo->createFunction(
            'UNIX_TIMESTAMP',
            static fn (mixed $t): ?int => $t === null ? null : (int) strtotime((string) $t),
            1,
        );
        $pdo->exec(
            'CREATE TABLE user (
                id BLOB PRIMARY KEY, username TEXT DEFAULT "", password TEXT DEFAULT "",
                mailbox TEXT NULL, email TEXT NULL, display_name TEXT NULL, type INTEGER DEFAULT 0,
                verified INTEGER DEFAULT 0, avatar INTEGER DEFAULT 0, deleted INTEGER DEFAULT 0,
                deletion_mode TEXT DEFAULT "none", theme TEXT NULL, session_epoch INTEGER DEFAULT 0,
                token_hash TEXT NULL, token_type INTEGER NULL, token_used INTEGER DEFAULT 0,
                token_expires_at TEXT NULL
            )'
        );
        return $pdo;
    }

    // ── 3. Identicon seed ───────────────────────────────────────────────────
    echo "identicon seed is keyed, not reproducible\n";

    $secretA = secretFor(str_repeat('a', 64));
    $secretB = secretFor(str_repeat('b', 64));
    $repoA   = new UserRepository(userPdo());
    $avA     = new AvatarService($repoA, new ImageSanitizer(), $secretA);
    $avB     = new AvatarService($repoA, new ImageSanitizer(), $secretB);

    $uid   = str_repeat('9f', 16);           // a 32-hex uid, as read off any page
    $email = 'alice@protonmail.com';

    $seed = $avA->identiconSeed($uid, $email);
    check('the seed is NOT the old sha256-able concatenation', $seed !== $uid . $email);
    check(
        'an attacker who guesses the address cannot recompute the seed',
        $seed !== hash('sha256', $uid . $email),
    );
    check('the seed is stable for the same install + user', $seed === $avA->identiconSeed($uid, $email));
    check('a different install secret gives a different seed', $seed !== $avB->identiconSeed($uid, $email));
    check(
        'a different recovery address still gives a different identicon',
        $seed !== $avA->identiconSeed($uid, 'bob@protonmail.com'),
    );
    check(
        'the uid/email boundary is unambiguous ("ab"+"cd" != "abc"+"d")',
        $avA->identiconSeed('ab', 'cd') !== $avA->identiconSeed('abc', 'd'),
    );

    // ── 4. Password floor ───────────────────────────────────────────────────
    echo "\npassword floor holds with password_regex empty\n";

    $session = new UserSession();
    $_SESSION = [];
    $svc = serviceWith($secretA, userPdo(), $session);
    $svc->setPasswordRegex([]);   // the shipped-and-then-emptied case

    // changePassword() runs mismatch → empty → LENGTH → regex → DB, so a short
    // password is rejected before any query.
    $shortResult = $svc->changePassword('deadbeef', 'old', 'a', 'a', tokenUnlock: true);
    check('the password "a" is rejected even with no regex rules', !$shortResult->isOk());

    $ids = [];
    foreach ($shortResult->diagnostics() as $d) { $ids[] = $d->id(); }
    check(
        'and the user is told the minimum length (translated diagnostic)',
        in_array('astrx.user/password_too_short', $ids, true),
    );

    check('the floor is a code constant, not config', UserService::MIN_PASSWORD_LENGTH >= 12);

    $justUnder = str_repeat('x', UserService::MIN_PASSWORD_LENGTH - 1);
    check(
        'one character under the floor is still rejected',
        !$svc->changePassword('deadbeef', '', $justUnder, $justUnder, tokenUnlock: true)->isOk(),
    );

    $huge = str_repeat('x', UserService::MAX_PASSWORD_LENGTH + 1);
    check(
        'an oversized password is rejected before it reaches argon2id',
        !$svc->changePassword('deadbeef', '', $huge, $huge, tokenUnlock: true)->isOk(),
    );

    $mismatch = $svc->changePassword('deadbeef', '', str_repeat('x', 20), 'other', tokenUnlock: true);
    check('mismatched passwords still fail first', !$mismatch->isOk());

    // ── 5. Login captcha ────────────────────────────────────────────────────
    echo "\nlogin captcha does not depend on client-owned state\n";

    $svc->setLoginCaptchaType(UserService::CAPTCHA_SHOW_ON_X_FAILED);
    $svc->setLoginCaptchaAttempts(3);
    $zero = $svc->shouldShowLoginCaptcha(0);
    check(
        'a visitor who dropped their cookie (fail count 0) STILL gets a captcha',
        $zero->isOk() && $zero->unwrap() === true,
    );

    $svc->setLoginCaptchaType(UserService::CAPTCHA_SHOW_ALWAYS);
    $always = $svc->shouldShowLoginCaptcha(0);
    check('ALWAYS still means always', $always->isOk() && $always->unwrap() === true);

    $svc->setLoginCaptchaType(UserService::CAPTCHA_SHOW_NEVER);
    $never = $svc->shouldShowLoginCaptcha(99);
    check(
        'NEVER remains an explicit operator opt-out',
        $never->isOk() && $never->unwrap() === false,
    );

    check(
        'the shipped config default is ALWAYS, not the threshold mode',
        (static function (): bool {
            /** @var mixed $cfg */
            $cfg = require dirname(__DIR__) . '/resources/config/User.config.php';
            return is_array($cfg)
                && is_array($cfg['UserService'] ?? null)
                && ($cfg['UserService']['login_captcha_type'] ?? null) === UserService::CAPTCHA_SHOW_ALWAYS;
        })(),
    );

    check(
        'username_regex bounds the username at the VARCHAR(64) the column is',
        (static function (): bool {
            /** @var mixed $cfg */
            $cfg = require dirname(__DIR__) . '/resources/config/User.config.php';
            if (!is_array($cfg) || !is_array($cfg['UserService'] ?? null)) { return false; }
            $rules = $cfg['UserService']['username_regex'] ?? [];
            if (!is_array($rules)) { return false; }
            foreach ($rules as $rule) {
                if (!is_array($rule) || !is_string($rule['regex'] ?? null)) { continue; }
                // 64 chars must pass, 65 must not.
                if (preg_match($rule['regex'], str_repeat('a', 64)) !== 1) { return false; }
                if (preg_match($rule['regex'], str_repeat('a', 65)) !== 0) { return false; }
            }
            return $rules !== [];
        })(),
    );

    // ── 6. Password change evicts other sessions ────────────────────────────
    echo "\npassword change evicts other sessions\n";

    $pdo = userPdo();
    $hex = str_repeat('ab', 16);
    $pdo->prepare('INSERT INTO user (id, username, session_epoch) VALUES (UNHEX(:id), "victim", 4)')
        ->execute([':id' => $hex]);

    /** Read session_epoch straight out of the table. */
    $epochOf = static function (PDO $pdo, string $hex): int {
        $stmt = $pdo->prepare('SELECT session_epoch FROM user WHERE id = UNHEX(:id)');
        $stmt->execute([':id' => $hex]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) $row['session_epoch'] : -1;
    };

    $_SESSION = [
        'logged_in' => true,
        'user'      => ['id' => $hex, 'username' => 'victim', 'display_name' => 'v',
                        'type' => 0, 'verified' => true, 'avatar' => false, 'epoch' => 4],
    ];
    $session2 = new UserSession();
    $svc2     = serviceWith($secretA, $pdo, $session2);
    $svc2->setPasswordRegex([]);

    $newPass = 'correct horse battery';
    $changed = $svc2->changePassword($hex, '', $newPass, $newPass, tokenUnlock: true);
    check('the password change succeeds', $changed->isOk());
    check(
        'it bumps the session epoch, evicting every session that adopted the old one',
        $epochOf($pdo, $hex) === 5,
    );
    check(
        'the session that made the change adopts the new epoch and survives',
        $session2->sessionEpoch() === 5,
    );

    // The recovery path runs logged-out, so nothing is re-adopted and EVERY
    // existing session of that account dies — which is the point of the flow.
    $_SESSION = [];
    $session3 = new UserSession();
    $svc3     = serviceWith($secretA, $pdo, $session3);
    $svc3->setPasswordRegex([]);
    $recovered = 'recovered passphrase';
    $reset = $svc3->changePassword($hex, '', $recovered, $recovered, tokenUnlock: true);
    check('the recovery reset path also evicts', $reset->isOk() && $epochOf($pdo, $hex) === 6);
    check('and adopts nothing, because it holds no session', $session3->sessionEpoch() === null);

    // An admin-set password evicts too (adminSetPasswordHash deliberately does not).
    $_SESSION = [];
    $svc4 = serviceWith($secretA, $pdo, new UserSession());
    $svc4->setPasswordRegex([]);
    check(
        'adminSetPassword() evicts the account\'s sessions',
        $svc4->adminSetPassword($hex, 'operator chosen pass')->isOk() && $epochOf($pdo, $hex) === 7,
    );
    check(
        'adminSetPasswordHash() (restore path) does NOT evict',
        $svc4->adminSetPasswordHash($hex, password_hash('x', PASSWORD_ARGON2ID))->isOk()
        && $epochOf($pdo, $hex) === 7,
    );

    // ── 7. TOTP secret at rest ──────────────────────────────────────────────
    echo "\nTOTP secret is encrypted at rest\n";

    $enc = new ReflectionMethod(UserService::class, 'encryptTotpSecret');
    $dec = new ReflectionMethod(UserService::class, 'decryptTotpSecret');

    $base32 = 'JBSWY3DPEHPK3PXP';
    $stored = $enc->invoke($svc, $base32);
    check('the stored value is not the cleartext base32 secret', $stored !== $base32);
    check('it does not contain the cleartext anywhere',          !str_contains($stored, $base32));
    check('it is marked as an envelope',                          str_starts_with($stored, 'enc:v1:'));
    check('it round-trips',                                       $dec->invoke($svc, $base32 === '' ? '' : $stored) === $base32);
    check('two encryptions of the same secret differ (random IV)', $enc->invoke($svc, $base32) !== $stored);
    check('an empty secret stays empty',                          $enc->invoke($svc, '') === '');

    // Rows written before this change hold plaintext and must keep working.
    check('a legacy plaintext row is still readable', $dec->invoke($svc, $base32) === $base32);

    // A row encrypted under a different install secret must not decode.
    $svcOther = serviceWith($secretB, userPdo(), $session);
    check(
        'a row from another install (wrong key) decodes to nothing, not garbage',
        $dec->invoke($svcOther, $stored) === '',
    );

    // Tampering must be caught by the MAC, not silently decrypted.
    $tampered = 'enc:v1:' . base64_encode(
        substr((string) base64_decode(substr($stored, 7), true), 0, -1) . 'X'
    );
    check('a tampered envelope fails its MAC', $dec->invoke($svc, $tampered) === '');

    echo "\n{$PASS} passed, {$FAIL} failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
