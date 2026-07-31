<?php
declare(strict_types=1);

namespace AstrX\User;

use AstrX\User\DeletionMode;

use AstrX\Config\Config;
use AstrX\Config\InjectConfig;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;
use AstrX\User\Diagnostic\UserLoginFailedDiagnostic;
use AstrX\User\Diagnostic\UserLoginRestrictedDiagnostic;
use AstrX\User\Diagnostic\UserNotVerifiedDiagnostic;
use AstrX\User\Diagnostic\UserRegistrationClosedDiagnostic;
use AstrX\User\Diagnostic\UserUsernameTakenDiagnostic;
use AstrX\User\Diagnostic\UserEmailTakenDiagnostic;
use AstrX\User\Diagnostic\UserMailboxTakenDiagnostic;
use AstrX\User\Diagnostic\UserInvalidUsernameDiagnostic;
use AstrX\User\Diagnostic\UserInvalidPasswordDiagnostic;
use AstrX\User\Diagnostic\UserInvalidMailboxDiagnostic;
use AstrX\User\Diagnostic\UserPasswordsMismatchDiagnostic;
use AstrX\User\Diagnostic\UserInvalidDateDiagnostic;
use AstrX\User\Diagnostic\UserTooYoungDiagnostic;
use AstrX\User\Diagnostic\UserEmptyFieldsDiagnostic;
use AstrX\User\Diagnostic\UserWrongPasswordDiagnostic;
use AstrX\User\Diagnostic\UserTokenNotFoundDiagnostic;
use AstrX\User\Diagnostic\UserTokenExpiredDiagnostic;
use AstrX\User\Diagnostic\UserTokenAlreadySentDiagnostic;
use AstrX\User\Diagnostic\UserNotFoundDiagnostic;

/**
 * User business logic.
 *
 * All methods return Result<T>. Callers drain diagnostics to their collector
 * and check isOk() before trusting the returned value.
 *
 * Password hashing: password_hash(PASSWORD_ARGON2ID) / password_verify().
 * User IDs: 32-char lowercase hex, generated with bin2hex(random_bytes(16)).
 * Tokens: 32-char hex raw token, stored as password_hash() in the DB.
 *
 * Email sending is intentionally NOT handled here — this service returns
 * token data and the calling controller is responsible for passing it to a
 * mailer. This decouples UserService from PHPMailer and makes it testable.
 *
 * Captcha policy constants match the old User class values for compatibility.
 */
final class UserService
{
    // Captcha display policy
    public const int CAPTCHA_SHOW_ALWAYS           = 0;
    public const int CAPTCHA_SHOW_NEVER            = 1;
    public const int CAPTCHA_SHOW_ON_X_FAILED      = 2;

    // Token types — mirror `token_type` column
    public const int TOKEN_RECOVER       = 0;
    public const int TOKEN_EMAIL_CHANGE  = 1;
    public const int TOKEN_EMAIL_VERIFY  = 2;
    public const int TOKEN_DELETE        = 3;

    /**
     * A fixed, valid Argon2id hash used ONLY as a constant-time decoy on the
     * "user not found" login branch (FIX M5). Running password_verify() against
     * it when no user exists makes that branch cost the same as a real
     * verification, closing a timing-based username-enumeration oracle. It is
     * never expected to match any real password.
     */
    private const string DUMMY_ARGON2ID =
        '$argon2id$v=19$m=65536,t=4,p=1$cDJiZmhhblJSVGFOS0Z1aA$ZIZhHVT4t7sWknDok3QU8wQQ4S1fnijafr8qCdFRY5c';


    /**
     * Normalise a username for availability checking.
     * Returns the username lowercased unless case-sensitive usernames are enabled,
     * in which case the original casing is preserved for the DB comparison.
     * The repository always applies LOWER() in SQL, so this only matters when
     * a separate case-sensitive check is needed.
     */
    private function normaliseUsernameForCheck(string $username): string
    {
        return $this->caseSensitiveUsernames ? $username : strtolower($username);
    }

    // -------------------------------------------------------------------------
    // Configuration (all injectable via #[InjectConfig])
    // -------------------------------------------------------------------------

    private int    $tokenTtl              = 21600;   // 6 hours
    private bool   $allowRegister         = true;
    private bool   $allowLoginNonVerified = true;
    private bool   $requireEmail          = true;
    private bool   $requireRecoveryEmail  = true;
    private bool   $requireBirthDate      = false;
    private bool   $requireDisplayName    = true;
    private int    $minimumAge            = 0;
    private int    $maximumAge            = 0;
    private int    $loginCaptchaType      = self::CAPTCHA_SHOW_ON_X_FAILED;
    private int    $loginCaptchaAttempts  = 3;
    private int    $registerCaptchaType   = self::CAPTCHA_SHOW_ALWAYS;
    private int    $recoverCaptchaType    = self::CAPTCHA_SHOW_ALWAYS;
    private int    $rememberMeTime        = 2592000; // 30 days
    /** @var array<int, array{regex:string,checking_for:bool,message:string,enabled:bool}> */
    private array  $usernameRegex         = [];
    /** @var array<int, array{regex:string,checking_for:bool,message:string,enabled:bool}> */
    private array  $passwordRegex         = [];
    private bool   $caseSensitiveUsernames = false;
    private int    $loginLockoutThreshold  = 10;    // failed logins before lockout; 0 = disabled
    private int    $loginLockoutCooldown   = 900;   // seconds a lockout lasts once triggered

    #[InjectConfig('token_expiration_time')]
    public function setTokenTtl(int $v): void { $this->tokenTtl = max(60, $v); }
    #[InjectConfig('allow_register')]
    public function setAllowRegister(bool $v): void { $this->allowRegister = $v; }
    #[InjectConfig('allow_login_non_verified_users')]
    public function setAllowLoginNonVerified(bool $v): void { $this->allowLoginNonVerified = $v; }
    #[InjectConfig('require_email')]
    public function setRequireEmail(bool $v): void { $this->requireEmail = $v; }
    #[InjectConfig('require_recovery_email')]
    public function setRequireRecoveryEmail(bool $v): void { $this->requireRecoveryEmail = $v; }

    /** Whether to send a verification email after registration (fix114). */
    private bool $sendVerificationEmail = true;
    /** Whether the password-recovery flow actually sends an email (fix114). */
    private bool $sendPasswordResetEmail = true;

    #[InjectConfig('send_verification_email')]
    public function setSendVerificationEmail(bool $v): void  { $this->sendVerificationEmail  = $v; }
    #[InjectConfig('send_password_reset_email')]
    public function setSendPasswordResetEmail(bool $v): void { $this->sendPasswordResetEmail = $v; }
    #[InjectConfig('require_birth_date')]
    public function setRequireBirthDate(bool $v): void { $this->requireBirthDate = $v; }
    #[InjectConfig('require_display_name')]
    public function setRequireDisplayName(bool $v): void { $this->requireDisplayName = $v; }
    #[InjectConfig('minimum_age')]
    public function setMinimumAge(int $v): void { $this->minimumAge = max(0, $v); }
    #[InjectConfig('maximum_age')]
    public function setMaximumAge(int $v): void { $this->maximumAge = max(0, $v); }
    #[InjectConfig('login_captcha_type')]
    public function setLoginCaptchaType(int $v): void { $this->loginCaptchaType = $v; }
    #[InjectConfig('login_captcha_attempts')]
    public function setLoginCaptchaAttempts(int $v): void { $this->loginCaptchaAttempts = max(1, $v); }
    #[InjectConfig('register_captcha_type')]
    public function setRegisterCaptchaType(int $v): void { $this->registerCaptchaType = $v; }
    #[InjectConfig('recover_captcha_type')]
    public function setRecoverCaptchaType(int $v): void { $this->recoverCaptchaType = $v; }
    #[InjectConfig('remember_me_time')]
    public function setRememberMeTime(int $v): void { $this->rememberMeTime = max(0, $v); }
    /** @param array<int, array{regex:string,checking_for:bool,message:string,enabled:bool}> $v */
    #[InjectConfig('username_regex')]
    public function setUsernameRegex(array $v): void { $this->usernameRegex = $v; }
    /** @param array<int, array{regex:string,checking_for:bool,message:string,enabled:bool}> $v */
    #[InjectConfig('password_regex')]
    public function setPasswordRegex(array $v): void { $this->passwordRegex = $v; }

    /**
     * When true, username uniqueness is checked with an exact match (case-sensitive).
     * When false (default), LOWER() is used in the SQL comparison.
     */
    #[InjectConfig('case_sensitive_usernames')]
    public function setCaseSensitiveUsernames(bool $v): void { $this->caseSensitiveUsernames = $v; }

    /** Number of failed logins that triggers a temporary lockout. 0 disables the feature. */
    #[InjectConfig('login_lockout_threshold')]
    public function setLoginLockoutThreshold(int $v): void { $this->loginLockoutThreshold = max(0, $v); }
    /** How long (seconds) a triggered lockout lasts. */
    #[InjectConfig('login_lockout_cooldown')]
    public function setLoginLockoutCooldown(int $v): void { $this->loginLockoutCooldown = max(1, $v); }

    // -------------------------------------------------------------------------

    public function __construct(
        private readonly UserRepository $repo,
        private readonly AvatarService  $avatarService,
    ) {}

    // -------------------------------------------------------------------------
    // Public getters (needed by controllers for conditional UI)
    // -------------------------------------------------------------------------

    public function requireEmail(): bool             { return $this->requireEmail; }
    public function sendVerificationEmail(): bool    { return $this->sendVerificationEmail; }
    public function sendPasswordResetEmail(): bool   { return $this->sendPasswordResetEmail; }
    public function requireRecoveryEmail(): bool { return $this->requireRecoveryEmail; }
    public function requireBirthDate(): bool     { return $this->requireBirthDate; }
    public function requireDisplayName(): bool   { return $this->requireDisplayName; }
    public function allowRegister(): bool              { return $this->allowRegister; }
    public function allowLoginNonVerifiedUsers(): bool   { return $this->allowLoginNonVerified; }

    // -------------------------------------------------------------------------
    // Captcha policy
    // -------------------------------------------------------------------------

    /**
     * Whether to show a captcha for the given form.
     * For 'login': pass the submitted username so we can look up login_attempts.
     *
     * @return Result<bool>
     */
    public function shouldShowCaptcha(string $form, ?string $username = null): Result
    {
        $type = match ($form) {
            'login'    => $this->loginCaptchaType,
            'register' => $this->registerCaptchaType,
            'recover'  => $this->recoverCaptchaType,
            default    => self::CAPTCHA_SHOW_ALWAYS,
        };

        if ($type === self::CAPTCHA_SHOW_ALWAYS) {
            return Result::ok(true);
        }
        if ($type === self::CAPTCHA_SHOW_NEVER) {
            return Result::ok(false);
        }

        // ON_X_FAILED_ATTEMPTS — needs username + DB lookup
        if ($form === 'login' && $username !== null && $username !== '') {
            $findResult = $this->repo->findByUsername($username);
            if (!$findResult->isOk()) {
                return Result::ok(true); // safe default: show captcha on DB error
            }
            /** @var array<string,mixed>|null $row */
                        $row = $findResult->unwrap();
                        if ($row === null) {
                return Result::ok(false); // user not found — don't reveal existence
            }
            return Result::ok((is_int($row['login_attempts']) ? $row['login_attempts'] : 0) >= $this->loginCaptchaAttempts);
        }

        return Result::ok(false);
    }

    /**
     * Decide whether the LOGIN form needs a captcha based on a caller-supplied
     * failure count (kept in the session, independent of account existence).
     *
     * shouldShowCaptcha('login', $username) does a DB lookup keyed on the
     * username, which leaks account existence: real users start showing a
     * captcha after N fails while unknown usernames never do. This variant is
     * driven purely by the caller's session counter so the captcha requirement
     * is identical whether or not the account exists (FIX M5b). The captcha
     * *policy* (always / never / on-N-failed) is still honoured.
     *
     * @return Result<bool>
     */
    public function shouldShowLoginCaptcha(int $failCount): Result
    {
        return match ($this->loginCaptchaType) {
            self::CAPTCHA_SHOW_ALWAYS => Result::ok(true),
            self::CAPTCHA_SHOW_NEVER  => Result::ok(false),
            default                   => Result::ok($failCount >= $this->loginCaptchaAttempts),
        };
    }

    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------

    /**
     * Attempt to log in a user.
     *
     * On success: returns the row suitable for UserSession::login(), and
     * increments last_access + resets login_attempts.
     * On failure: increments login_attempts (for captcha threshold) and
     * returns an appropriate diagnostic.
     *
     * @return Result<array<string,mixed>>
     */
    public function login(
        string $username,
        string $password,
        bool   $rememberMe,
    ): Result {
        if ($username === '' || $password === '') {
            return $this->opErr('empty_fields');
        }

        $findResult = $this->repo->findByUsername($username);
        if (!$findResult->isOk()) {
            return Result::err(null, $findResult->diagnostics());
        }

        /** @var array<string,mixed>|null $row */
        $row = $findResult->unwrap();

        // FIX M4: brute-force lockout. If this account is currently locked,
        // reject immediately WITHOUT running password_verify. (No lockout can
        // exist for a non-existent user, so this never leaks existence.)
        if ($row !== null && $this->loginLockoutThreshold > 0) {
            $lockedUntil = $this->intVal($row['login_locked_until'] ?? null);
            if ($lockedUntil > time()) {
                // Return the generic failure (not 'login_restricted'): a distinct
                // "locked" message only ever appears for an EXISTING account, which
                // is an account-existence oracle. The lockout still applies below.
                // Run one dummy verify first so a locked (existing) account costs
                // the same as the not-found path below — the early return would
                // otherwise be a *timing* existence oracle (fast = locked/existing,
                // slow = argon2 for not-found).
                password_verify($password, self::DUMMY_ARGON2ID);
                return $this->opErr('login_failed');
            }
        }

        // FIX M5a: constant-time verification. Always call password_verify —
        // even when the user does not exist — against a dummy hash, so the
        // "not found" branch costs the same as a real one (no timing oracle).
        $storedHash = ($row !== null && isset($row['password']) && is_scalar($row['password']))
            ? (string) $row['password']
            : self::DUMMY_ARGON2ID;
        $passwordOk = password_verify($password, $storedHash);

        if ($row === null || !$passwordOk) {
            // Only touch the DB / count attempts when the user actually exists
            // (never leak existence for unknown usernames).
            if ($row !== null) {
                $hexId    = is_scalar($row['id']) ? (string) $row['id'] : '';
                $attempts = $this->intVal($row['login_attempts'] ?? null);
                $this->repo->updateLoginAttempts($hexId, +1);
                if ($this->loginLockoutThreshold > 0 && ($attempts + 1) >= $this->loginLockoutThreshold) {
                    $this->repo->setLockout($hexId, time() + $this->loginLockoutCooldown);
                }
            }
            return $this->opErr('login_failed');
        }

        if (!$this->allowLoginNonVerified && !(bool) $row['verified']) {
            return $this->opErr('not_verified');
        }

        // Closed accounts (keep_visible / keep_suspended / …) must not log in even
        // though keep_visible keeps `deleted = 0` for content visibility — the
        // `deleted = 0` filter alone doesn't catch it (R3-15). NONE is the only
        // login-allowing DeletionMode.
        $deletionMode = is_scalar($row['deletion_mode'] ?? null) ? (string) $row['deletion_mode'] : '';
        if ($deletionMode !== '' && $deletionMode !== 'none') {
            return $this->opErr('login_failed');
        }

        // Success: update DB, set session
        $hexId = (is_scalar($row['id']) ? (string)$row['id'] : '');

        // FIX (rehash-on-verify): transparently upgrade the stored hash if the
        // algorithm/parameters have changed since it was written.
        if (password_needs_rehash($storedHash, PASSWORD_ARGON2ID)) {
            $this->repo->updatePassword($hexId, password_hash($password, PASSWORD_ARGON2ID));
        }

        $this->repo->updateLoginAttempts($hexId, -1); // reset to 0
        $this->repo->setLockout($hexId, null);        // FIX M4: clear any lockout
        $this->repo->updateLastAccess($hexId);

        // FIX (remember-me): regenerate the session ID FIRST — the old order set
        // the cookie to a session id that session_regenerate_id(true) destroyed
        // one line later. Then write the cookie carrying the NEW id, using the
        // array-options form of setcookie so we can pin SameSite.
        session_regenerate_id(true);

        if ($rememberMe && $this->rememberMeTime > 0) {
            // Persist the remember-me expiry so a later session-ID regeneration
            // re-issues the cookie with THIS expiry rather than a session-lifetime
            // one (otherwise the next request's regen silently reverts remember-me).
            $_SESSION['_remember_until'] = time() + $this->rememberMeTime;
            $params = session_get_cookie_params();
            setcookie(
                (string) session_name(),
                (string) session_id(),
                [
                    'expires'  => time() + $this->rememberMeTime,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => true,
                    'samesite' => 'Lax',
                ],
            );
        }

        return Result::ok($row);
    }

    /** Coerce a mixed DB/scalar value to int, defaulting to 0. */
    private function intVal(mixed $v): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }

    // -------------------------------------------------------------------------
    // Register
    // -------------------------------------------------------------------------

    /**
     * Register a new user.
     *
     * @return Result<string> Hex user ID on success.
     */
    public function register(
        string  $username,
        string  $password,
        string  $repeat,
        ?string $mailbox,
        ?string $email,
        ?string $displayName,
        ?int    $month,
        ?int    $day,
        ?int    $year,
    ): Result {
        if (!$this->allowRegister) {
            return $this->opErr('registration_closed');
        }

        if ($username === '' || $password === '') {
            return $this->opErr('empty_fields');
        }

        // Password validation
        if ($password !== $repeat) {
            return $this->opErr('passwords_mismatch');
        }
        $pwErr = $this->checkRegex($this->passwordRegex, $password);
        if ($pwErr !== null) {
            return $this->opErr('invalid_password', $pwErr);
        }

        // Username validation + availability
        $unErr = $this->checkRegex($this->usernameRegex, $username);
        if ($unErr !== null) {
            return $this->opErr('invalid_username', $unErr);
        }
        $availResult = $this->repo->isUsernameAvailable($this->normaliseUsernameForCheck($username));
        if (!$availResult->isOk()) {
            return Result::err(null, $availResult->diagnostics());
        }
        if ($availResult->unwrap() === false) {
            return $this->opErr('username_taken');
        }

        // Display name
        if ($this->requireDisplayName && ($displayName === null || $displayName === '')) {
            return $this->opErr('empty_fields');
        }

        // Mailbox — the local-part of the user's mailbox address (e.g. 'alice').
        // The domain is fixed per-installation (configured in Imap.config.php).
        // Valid characters: letters, digits, dots, hyphens, underscores; 1-64 chars.
        if ($this->requireEmail) {
            $mailbox = $mailbox ?? '';
            if ($mailbox === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-_]{0,63}$/', $mailbox)) {
                return $this->opErr('invalid_mailbox');
            }
            $mbResult = $this->repo->isMailboxAvailable($mailbox);
            if (!$mbResult->isOk()) {
                return Result::err(null, $mbResult->diagnostics());
            }
            if ($mbResult->unwrap() === false) {
                return $this->opErr('mailbox_taken');
            }
        }

        // Recovery email
        if ($this->requireRecoveryEmail) {
            $email = $email ?? '';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->opErr('empty_fields');
            }
            $emResult = $this->repo->isEmailAvailable($email);
            if (!$emResult->isOk()) {
                return Result::err(null, $emResult->diagnostics());
            }
            if ($emResult->unwrap() === false) {
                return $this->opErr('email_taken');
            }
        }

        // Birth date
        $birth = null;
        if ($this->requireBirthDate) {
            if ($month === null || $day === null || $year === null || !checkdate($month, $day, $year)) {
                return $this->opErr('invalid_date');
            }
            $birth     = sprintf('%04d-%02d-%02d', $year, $month, $day);
            // minimum_age / maximum_age are in YEARS. Use an EXACT calendar-year
            // diff (not seconds / 365.25) so a registrant exactly on their birthday
            // isn't rejected as underage by averaged-year rounding (R3-21).
            $birthDt  = date_create($birth);
            $nowDt    = date_create('today');
            $ageYears = ($birthDt !== false && $nowDt !== false)
                ? (int) $birthDt->diff($nowDt)->y
                : 0;
            if ($this->minimumAge > 0 && $ageYears < $this->minimumAge) {
                return $this->opErr('too_young');
            }
            if ($this->maximumAge > 0 && $ageYears > $this->maximumAge) {
                return $this->opErr('invalid_date');
            }
        }

        $hexId        = bin2hex(random_bytes(16));
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);

        $createResult = $this->repo->create(
            $hexId, $username, $passwordHash,
            $this->requireEmail ? $mailbox : null,
            $this->requireRecoveryEmail ? $email : null,
            $this->requireDisplayName ? $displayName : null,
            $birth,
        );

        if (!$createResult->isOk()) {
            return Result::err(null, $createResult->diagnostics());
        }

        return Result::ok($hexId);
    }

    // -------------------------------------------------------------------------
    // Token operations
    // -------------------------------------------------------------------------

    /**
     * Generate an email action token and store its hash in the DB.
     *
     * Returns an array with:
     *   'token'      — the raw token (to embed in the email link)
     *   'user_id'    — the hex user ID
     *   'expires_at' — unix timestamp
     *
     * @return Result<array{token:string,user_id:string,expires_at:int}>
     */
    public function generateToken(string $hexId, int $tokenType): Result
    {
        // Check if a valid non-expired token of the same type already exists.
        // Re-issuing too quickly wastes the user's inbox and indicates abuse.
        $existing = $this->repo->findTokenData($hexId);
        /** @var array<string,mixed>|null $existingToken */
        $existingToken = $existing->isOk() ? $existing->unwrap() : null;
        if ($existingToken !== null) {
            /** @var array<string,mixed> $row */
            $row = $existingToken;
            if (
                (is_int($row['token_type']) ? $row['token_type'] : 0)  === $tokenType &&
                (is_int($row['token_used']) ? $row['token_used'] : 0)  === 0 &&
                (is_int($row['token_expires_at']) ? $row['token_expires_at'] : 0) > time()
            ) {
                return $this->opErr('token_already_sent');
            }
        }

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_ARGON2ID);
        $expiresAt = time() + $this->tokenTtl;

        $storeResult = $this->repo->setToken($hexId, $tokenHash, $tokenType, $expiresAt);
        if (!$storeResult->isOk()) {
            return Result::err(null, $storeResult->diagnostics());
        }

        return Result::ok([
                              'token'      => $rawToken,
                              'user_id'    => $hexId,
                              'expires_at' => $expiresAt,
                          ]);
    }

    /**
     * Verify a token link. On success: marks token as used, updates verification
     * status, returns the token_type so the caller can redirect appropriately.
     *
     * For TOKEN_DELETE: the caller should complete the deletion after verify.
     *
     * @return Result<int> Token type on success.
     */
    public function verifyToken(string $hexId, string $rawToken): Result
    {
        $findResult = $this->repo->findById($hexId);
        if (!$findResult->isOk()) {
            return Result::err(null, $findResult->diagnostics());
        }

        /** @var array<string,mixed>|null $row */
        $row = $findResult->unwrap();
        if ($row === null || (bool) $row['deleted']) {
            return $this->opErr('token_not_found');
        }

        if (
            $row['token_hash'] === null ||
            (is_int($row['token_used']) ? $row['token_used'] : 0) === 1 ||
            !password_verify($rawToken, (is_scalar($row['token_hash']) ? (string)$row['token_hash'] : ''))
        ) {
            return $this->opErr('token_not_found');
        }

        if ((is_int($row['token_expires_at']) ? $row['token_expires_at'] : 0) < time()) {
            return $this->opErr('token_expired');
        }

        $tokenType = (is_int($row['token_type']) ? $row['token_type'] : 0);

        // Mark token as used
        $this->repo->markTokenUsed($hexId);

        // Email verify and email change both confirm the address
        if ($tokenType === self::TOKEN_EMAIL_VERIFY || $tokenType === self::TOKEN_EMAIL_CHANGE) {
            $this->repo->setVerified($hexId);
        }

        return Result::ok($tokenType);
    }

    /**
     * Build a UserSession::login()-ready row for a user whose identity was just
     * proven out-of-band (e.g. a verified single-use recovery token). Mirrors the
     * shape returned by login() and carries NO password. Used to authenticate a
     * logged-out user who clicked a valid recovery link so they can set a new
     * password (see UserController's token branch).
     *
     * @return Result<array{id:string,username:string,display_name:string,type:int,verified:bool,avatar:bool,mailbox:string,theme:string}>
     */
    public function sessionRowFor(string $hexId): Result
    {
        $find = $this->repo->findById($hexId);
        if (!$find->isOk()) {
            return Result::err(null, $find->diagnostics());
        }
        /** @var array<string,mixed>|null $row */
        $row = $find->unwrap();
        if ($row === null || (bool) ($row['deleted'] ?? false)) {
            return $this->opErr('user_not_found');
        }
        return Result::ok([
            'id'           => is_scalar($row['id'] ?? null) ? (string) $row['id'] : $hexId,
            'username'     => is_scalar($row['username'] ?? null) ? (string) $row['username'] : '',
            'display_name' => is_scalar($row['display_name'] ?? null) ? (string) $row['display_name'] : '',
            // 0 = GUEST is a defensive fallback; findById always returns `type`.
            'type'         => is_int($row['type'] ?? null) ? $row['type'] : (is_numeric($row['type'] ?? null) ? (int) $row['type'] : 0),
            'verified'     => (bool) ($row['verified'] ?? false),
            'avatar'       => (bool) ($row['avatar'] ?? false),
            'mailbox'      => is_scalar($row['mailbox'] ?? null) ? (string) $row['mailbox'] : '',
            'theme'        => is_scalar($row['theme'] ?? null) ? (string) $row['theme'] : '',
        ]);
    }

    /**
     * The user's stored recovery email (null if none / unknown), for resending a
     * verification link from settings.
     *
     * @return Result<string|null>
     */
    public function recoveryEmailFor(string $hexId): Result
    {
        return $this->repo->emailFor($hexId);
    }

    // -------------------------------------------------------------------------
    // Settings changes
    // -------------------------------------------------------------------------

    /** @return Result<bool> */
    public function changePassword(
        string $hexId,
        string $oldPassword,
        string $newPassword,
        string $repeat,
        bool   $tokenUnlock = false, // true when user arrived via recovery token
    ): Result {
        if ($newPassword !== $repeat) {
            return $this->opErr('passwords_mismatch');
        }
        if ($newPassword === '') {
            return $this->opErr('empty_fields');
        }

        $pwErr = $this->checkRegex($this->passwordRegex, $newPassword);
        if ($pwErr !== null) {
            return $this->opErr('invalid_password', $pwErr);
        }

        if (!$tokenUnlock) {
            // Verify current password. Use findPasswordHash() — findById() does
            // NOT select the `password` column, so reading $row['password'] there
            // raised an "undefined array key" warning (forced 500 under the error
            // handler) and left $pwHash = '', making password_verify always fail;
            // the non-recovery change-password path was effectively broken.
            $hashResult = $this->repo->findPasswordHash($hexId);
            if (!$hashResult->isOk()) {
                return Result::err(null, $hashResult->diagnostics());
            }
            $pwHash = $hashResult->unwrap();
            if ($pwHash === null || !password_verify($oldPassword, $pwHash)) {
                return $this->opErr('wrong_password');
            }
        }

        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        return $this->repo->updatePassword($hexId, $hash);
    }

    /** @return Result<bool> */
    public function adminSetPassword(string $hexId, string $password): Result
    {
        if ($password === '') {
            return $this->opErr('empty_fields');
        }

        $pwErr = $this->checkRegex($this->passwordRegex, $password);
        if ($pwErr !== null) {
            return $this->opErr('invalid_password', $pwErr);
        }

        return $this->repo->updatePassword($hexId, password_hash($password, PASSWORD_ARGON2ID));
    }

    /** @return Result<bool> */
    public function adminSetPasswordHash(string $hexId, string $passwordHash): Result
    {
        if ($passwordHash === '') {
            return $this->opErr('empty_fields');
        }

        $info = password_get_info($passwordHash);
        $algo = $info['algo'] ?? 0;
        if ($algo === 0) {
            return $this->opErr('invalid_password', 'Password hash is not recognised by password_get_info().');
        }

        return $this->repo->updatePassword($hexId, $passwordHash);
    }

    /** @return Result<bool> */
    public function changeUsername(string $hexId, string $username): Result
    {
        if ($username === '') {
            return $this->opErr('empty_fields');
        }
        $unErr = $this->checkRegex($this->usernameRegex, $username);
        if ($unErr !== null) {
            return $this->opErr('invalid_username', $unErr);
        }
        $availResult = $this->repo->isUsernameAvailable($this->normaliseUsernameForCheck($username));
        if (!$availResult->isOk()) {
            return Result::err(null, $availResult->diagnostics());
        }
        if ($availResult->unwrap() === false) {
            return $this->opErr('username_taken');
        }
        return $this->repo->updateUsername($hexId, $username);
    }

    /** @return Result<bool> */
    public function changeDisplayName(string $hexId, string $displayName): Result
    {
        if ($displayName === '') {
            return $this->opErr('empty_fields');
        }
        return $this->repo->updateDisplayName($hexId, $displayName);
    }

    /**
     * Change recovery email. If verification is required, returns
     * Result::ok('verify_required') and the caller must send a token email.
     *
     * @return Result<bool|string>
     */
    public function changeRecoveryEmail(string $hexId, string $email): Result
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->opErr('empty_fields');
        }
        $emResult = $this->repo->isEmailAvailable($email);
        if (!$emResult->isOk()) {
            return Result::err(null, $emResult->diagnostics());
        }
        if ($emResult->unwrap() === false) {
            return $this->opErr('email_taken');
        }
        $r = $this->repo->updateRecoveryEmail($hexId, $email);
        if (!$r->isOk()) {
            return Result::err(null, $r->diagnostics());
        }
        return Result::ok(true);
    }

    /**
     * Update the user's theme preference. Empty string clears the override
     * (revert to the global theme). The caller should also call
     * UserSession::updateTheme() so the change takes effect in this request.
     *
     * No validation is done against the list of installed themes here — the
     * controller is expected to check that, because the list comes from the
     * filesystem and only ThemeService knows it.
     *
     * @return Result<bool>
     */
    public function changeTheme(string $hexId, string $theme): Result
    {
        return $this->repo->updateTheme($hexId, $theme === '' ? null : $theme);
    }


    /**
     * Delete or retire a user account using the specified mode.
     *
     * User-initiated deletion (from settings) requires correct password unless
     * a delete token was already verified ($tokenUnlock=true).
     * Admin-initiated deletion skips password verification entirely.
     *
     * For hard_redact and full_delete, the avatar file is also removed from disk.
     * For hard_redact, comments are reassigned to the ghost account first.
     *
     * @return Result<bool>
     */
    public function delete(
        string       $hexId,
        DeletionMode $mode         = DeletionMode::SOFT_REDACT,
        ?string      $password     = null,
        bool         $tokenUnlock  = false,
        bool         $adminBypass  = false,
    ): Result {
        // Password check for user-initiated deletion
        if (!$adminBypass && !$tokenUnlock) {
            if ($password === null || $password === '') {
                return $this->opErr('wrong_password');
            }
            $hashResult = $this->repo->findPasswordHash($hexId);
            if (!$hashResult->isOk()) {
                return Result::err(null, $hashResult->diagnostics());
            }
            $hash = $hashResult->unwrap();
            if ($hash === null || !password_verify($password, (string)$hash)) {
                return $this->opErr('wrong_password');
            }
        }

        return match ($mode) {
            DeletionMode::FULL_DELETE    => $this->doFullDelete($hexId),
            DeletionMode::HARD_REDACT    => $this->doHardRedact($hexId),
            DeletionMode::SOFT_REDACT    => $this->repo->softRedact($hexId),
            DeletionMode::KEEP_VISIBLE   => $this->repo->keepVisible($hexId),
            DeletionMode::KEEP_SUSPENDED => $this->repo->keepSuspended($hexId),
            DeletionMode::NONE           => Result::ok(true), // no-op
        };
    }

    /** @return Result<bool> */
    private function doFullDelete(string $hexId): Result
    {
        // Remove avatar file before deleting the row.
        $this->avatarService->removeAvatar($hexId);
        return $this->repo->fullDelete($hexId);
    }

    /** @return Result<bool> */
    private function doHardRedact(string $hexId): Result
    {
        // Step 1: reassign comments to ghost (preserves thread structure).
        $reassign = $this->repo->reassignCommentsToGhost($hexId);
        if (!$reassign->isOk()) {
            return Result::err(null, $reassign->diagnostics());
        }
        // Step 2: remove avatar file.
        $this->avatarService->removeAvatar($hexId);
        // Step 3: wipe PII and create tombstone.
        return $this->repo->hardRedact($hexId);
    }

    /**
     * Initiate password recovery. Returns the user row needed to generate a
     * token. The calling controller must call generateToken() and send the email.
     *
     * @return Result<array<string,mixed>>
     */
    public function initiateRecovery(string $usernameOrEmail): Result
    {
        if ($usernameOrEmail === '') {
            return $this->opErr('empty_fields');
        }

        $findResult = $this->repo->findByUsernameOrEmail($usernameOrEmail);
        if (!$findResult->isOk()) {
            return Result::err(null, $findResult->diagnostics());
        }

        /** @var array<string,mixed>|null $row */

        $row = $findResult->unwrap();
        if ($row === null) {
            return $this->opErr('user_not_found');
        }

        return Result::ok($row);
    }

    // -------------------------------------------------------------------------
    // Token-unlock check (for settings: skip old_password after recovery token)
    // -------------------------------------------------------------------------

    /**
     * Check if the user has a consumed (used) token of the given type,
     * meaning they arrived via a recovery/verification email link.
     * Used by the settings controller to skip the "old password" field.
     */
    public function hasUsedToken(string $hexId, int $tokenType): bool
    {
        $result = $this->repo->findTokenData($hexId);
        if (!$result->isOk() || $result->unwrap() === null) {
            return false;
        }
        /** @var array<string,mixed> $row */
        $row = $result->unwrap();
        return (is_int($row['token_type']) ? $row['token_type'] : 0) === $tokenType && (is_int($row['token_used']) ? $row['token_used'] : 0) === 1;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return Result<never> */
    private function opErr(string $operation, string $detail = ''): Result
    {
        $diagnostic = match ($operation) {
            'login_failed'         => new UserLoginFailedDiagnostic('astrx.user/login_failed', DiagnosticLevel::NOTICE),
            'login_restricted'     => new UserLoginRestrictedDiagnostic('astrx.user/login_restricted', DiagnosticLevel::WARNING),
            'not_verified'         => new UserNotVerifiedDiagnostic('astrx.user/not_verified', DiagnosticLevel::NOTICE),
            'registration_closed'  => new UserRegistrationClosedDiagnostic('astrx.user/registration_closed', DiagnosticLevel::NOTICE),
            'username_taken'       => new UserUsernameTakenDiagnostic('astrx.user/username_taken', DiagnosticLevel::NOTICE),
            'email_taken'          => new UserEmailTakenDiagnostic('astrx.user/email_taken', DiagnosticLevel::NOTICE),
            'mailbox_taken'        => new UserMailboxTakenDiagnostic('astrx.user/mailbox_taken', DiagnosticLevel::NOTICE),
            'invalid_username'     => new UserInvalidUsernameDiagnostic('astrx.user/invalid_username', DiagnosticLevel::NOTICE, $detail),
            'invalid_password'     => new UserInvalidPasswordDiagnostic('astrx.user/invalid_password', DiagnosticLevel::NOTICE, $detail),
            'invalid_mailbox'      => new UserInvalidMailboxDiagnostic('astrx.user/invalid_mailbox', DiagnosticLevel::NOTICE),
            'passwords_mismatch'   => new UserPasswordsMismatchDiagnostic('astrx.user/passwords_mismatch', DiagnosticLevel::NOTICE),
            'invalid_date'         => new UserInvalidDateDiagnostic('astrx.user/invalid_date', DiagnosticLevel::NOTICE),
            'too_young'            => new UserTooYoungDiagnostic('astrx.user/too_young', DiagnosticLevel::NOTICE),
            'empty_fields'         => new UserEmptyFieldsDiagnostic('astrx.user/empty_fields', DiagnosticLevel::NOTICE),
            'wrong_password'       => new UserWrongPasswordDiagnostic('astrx.user/wrong_password', DiagnosticLevel::NOTICE),
            'token_not_found'      => new UserTokenNotFoundDiagnostic('astrx.user/token_not_found', DiagnosticLevel::NOTICE),
            'token_expired'        => new UserTokenExpiredDiagnostic('astrx.user/token_expired', DiagnosticLevel::NOTICE),
            'token_already_sent'   => new UserTokenAlreadySentDiagnostic('astrx.user/token_already_sent', DiagnosticLevel::NOTICE),
            'user_not_found'       => new UserNotFoundDiagnostic('astrx.user/not_found', DiagnosticLevel::NOTICE),
            default                => new UserEmptyFieldsDiagnostic('astrx.user/unknown', DiagnosticLevel::WARNING),
        };
        return Result::err(null, Diagnostics::of($diagnostic));
    }

    /**
     * Apply a regex filter array. Returns the first error message on failure
     * or null if all checks pass. Mirrors the old checkRegexFilter() function.
     */
    /** @param array<int,array<string,mixed>> $rules */
    private function checkRegex(array $rules, string $value): ?string
    {
        ksort($rules);
        foreach ($rules as $rule) {
            if (!(bool) ($rule['enabled'] ?? false)) {
                continue;
            }
            /** @var array<string,mixed> $rule */
            $regex       = is_string($rule['regex'] ?? null) ? (string)$rule['regex'] : '';
            $checkingFor = (bool) ($rule['checking_for'] ?? true);
            $message     = is_string($rule['message'] ?? null) ? (string)$rule['message'] : '';
            if ($regex === '') {
                continue;
            }
            $matches = (bool) preg_match($regex, $value);
            if ($matches === $checkingFor) {
                return $message; // rule triggered → validation failed
            }
        }
        return null;
    }
}
