<?php
declare(strict_types=1);

namespace AstrX\User;

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

    // -------------------------------------------------------------------------

    public function __construct(private readonly UserRepository $repo) {}

    // -------------------------------------------------------------------------
    // Public getters (needed by controllers for conditional UI)
    // -------------------------------------------------------------------------

    public function requireEmail(): bool         { return $this->requireEmail; }
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
            $row = $findResult->unwrap();
            if ($row === null) {
                return Result::ok(false); // user not found — don't reveal existence
            }
            return Result::ok((int) $row['login_attempts'] >= $this->loginCaptchaAttempts);
        }

        return Result::ok(false);
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

        $row = $findResult->unwrap();

        if ($row === null || !password_verify($password, (string) $row['password'])) {
            // Increment attempts if we found a user (don't leak existence otherwise)
            if ($row !== null) {
                $this->repo->updateLoginAttempts((string) $row['id'], +1);
            }
            return $this->opErr('login_failed');
        }

        if (!$this->allowLoginNonVerified && !(bool) $row['verified']) {
            return $this->opErr('not_verified');
        }

        // Success: update DB, set session
        $hexId = (string) $row['id'];
        $this->repo->updateLoginAttempts($hexId, -1); // reset to 0
        $this->repo->updateLastAccess($hexId);

        if ($rememberMe && $this->rememberMeTime > 0) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                (string) session_id(),
                time() + $this->rememberMeTime,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly'],
            );
        }

        // Regenerate session ID on login to prevent session fixation
        session_regenerate_id(true);

        return Result::ok($row);
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
        $availResult = $this->repo->isUsernameAvailable($username);
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
            $ageSeconds = time() - strtotime($birth);
            if ($this->minimumAge > 0 && $ageSeconds < $this->minimumAge) {
                return $this->opErr('too_young');
            }
            if ($this->maximumAge > 0 && $ageSeconds > $this->maximumAge) {
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
        if ($existing->isOk() && $existing->unwrap() !== null) {
            $row = $existing->unwrap();
            if (
                (int) $row['token_type']  === $tokenType &&
                (int) $row['token_used']  === 0 &&
                (int) $row['token_expires_at'] > time()
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

        $row = $findResult->unwrap();

        if ($row === null || (bool) $row['deleted']) {
            return $this->opErr('token_not_found');
        }

        if (
            $row['token_hash'] === null ||
            (int) $row['token_used'] === 1 ||
            !password_verify($rawToken, (string) $row['token_hash'])
        ) {
            return $this->opErr('token_not_found');
        }

        if ((int) $row['token_expires_at'] < time()) {
            return $this->opErr('token_expired');
        }

        $tokenType = (int) $row['token_type'];

        // Mark token as used
        $this->repo->markTokenUsed($hexId);

        // Email verify and email change both confirm the address
        if ($tokenType === self::TOKEN_EMAIL_VERIFY || $tokenType === self::TOKEN_EMAIL_CHANGE) {
            $this->repo->setVerified($hexId);
        }

        return Result::ok($tokenType);
    }

    // -------------------------------------------------------------------------
    // Settings changes
    // -------------------------------------------------------------------------

    /** @return Result<true> */
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
            // Verify current password
            $findResult = $this->repo->findById($hexId);
            if (!$findResult->isOk()) {
                return Result::err(null, $findResult->diagnostics());
            }
            $row = $findResult->unwrap();
            if ($row === null || !password_verify($oldPassword, (string) ($row['password'] ?? ''))) {
                return $this->opErr('wrong_password');
            }
        }

        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        return $this->repo->updatePassword($hexId, $hash);
    }

    /** @return Result<true> */
    public function changeUsername(string $hexId, string $username): Result
    {
        if ($username === '') {
            return $this->opErr('empty_fields');
        }
        $unErr = $this->checkRegex($this->usernameRegex, $username);
        if ($unErr !== null) {
            return $this->opErr('invalid_username', $unErr);
        }
        $availResult = $this->repo->isUsernameAvailable($username);
        if (!$availResult->isOk()) {
            return Result::err(null, $availResult->diagnostics());
        }
        if ($availResult->unwrap() === false) {
            return $this->opErr('username_taken');
        }
        return $this->repo->updateUsername($hexId, $username);
    }

    /** @return Result<true> */
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
     * @return Result<true|string>
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
            return $r;
        }
        return Result::ok(true);
    }

    /**
     * Soft-delete the user's account. Requires correct password unless a
     * delete token has already been verified (tokenUnlock=true).
     *
     * @return Result<true>
     */
    public function delete(string $hexId, ?string $password, bool $tokenUnlock = false): Result
    {
        if (!$tokenUnlock) {
            if ($password === null || $password === '') {
                return $this->opErr('wrong_password');
            }
            $hashResult = $this->repo->findPasswordHash($hexId);
            if (!$hashResult->isOk()) {
                return Result::err(null, $hashResult->diagnostics());
            }
            $hash = $hashResult->unwrap();
            if ($hash === null || !password_verify($password, $hash)) {
                return $this->opErr('wrong_password');
            }
        }

        return $this->repo->softDelete($hexId);
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
        $row = $result->unwrap();
        return (int) $row['token_type'] === $tokenType && (int) $row['token_used'] === 1;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

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
    private function checkRegex(array $rules, string $value): ?string
    {
        ksort($rules);
        foreach ($rules as $rule) {
            if (!(bool) ($rule['enabled'] ?? false)) {
                continue;
            }
            $regex       = (string) ($rule['regex'] ?? '');
            $checkingFor = (bool) ($rule['checking_for'] ?? true);
            $message     = (string) ($rule['message'] ?? '');
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
