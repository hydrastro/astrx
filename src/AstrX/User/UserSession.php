<?php
declare(strict_types=1);

namespace AstrX\User;

/**
 * Typed wrapper around the user portion of $_SESSION.
 *
 * Stored layout:
 *   $_SESSION['logged_in']   bool
 *   $_SESSION['user']        array{
 *     id: string, username: string, display_name: string,
 *     type: int, verified: bool, avatar: bool
 *   }
 *
 * All read methods return safe defaults so controllers never have to check
 * isLoggedIn() before calling e.g. userId() — they just won't get useful
 * values back and must handle the "not logged in" case separately.
 */
final class UserSession
{
    private const KEY        = 'user';
    private const LOGGED_IN  = 'logged_in';

    // -------------------------------------------------------------------------
    // State inspection
    // -------------------------------------------------------------------------

    public function isLoggedIn(): bool
    {
        return ($_SESSION[self::LOGGED_IN] ?? false) === true;
    }

    /** @return array<string,mixed> */
    private function sessionData(): array
    {
        $raw = $_SESSION[self::KEY] ?? null;
        if (!is_array($raw)) { return []; }
        /** @var array<string,mixed> $raw */
        return $raw;
    }

    public function userId(): string
    {
        $v = $this->sessionData()['id'] ?? ''; return is_scalar($v) ? (string)$v : '';
    }

    public function username(): string
    {
        $v = $this->sessionData()['username'] ?? ''; return is_scalar($v) ? (string)$v : '';
    }

    public function displayName(): string
    {
        $v = $this->sessionData()['display_name'] ?? ''; return is_scalar($v) ? (string)$v : '';
    }

    public function userType(): UserGroup
    {
        $t = $this->sessionData()['type'] ?? UserGroup::GUEST->value;
        $raw = is_int($t) ? $t : (is_numeric($t) ? (int)$t : UserGroup::GUEST->value);
        return UserGroup::tryFrom($raw) ?? UserGroup::GUEST;
    }

    public function isVerified(): bool
    {
        return (bool) ($this->sessionData()['verified'] ?? false);
    }

    public function hasAvatar(): bool
    {
        return (bool) ($this->sessionData()['avatar'] ?? false);
    }

    public function isAdmin(): bool
    {
        return $this->isLoggedIn() && $this->userType() === UserGroup::ADMIN;
    }

    public function isMod(): bool
    {
        return $this->isLoggedIn() &&
               in_array($this->userType(), [UserGroup::ADMIN, UserGroup::MOD], true);
    }

    // -------------------------------------------------------------------------
    // Mutations — called by UserService after successful operations
    // -------------------------------------------------------------------------

    /**
     * Persist user data to session after successful login / token verification.
     *
     * @param array{id:string,username:string,display_name:string,type:int,verified:int|bool,avatar:int|bool,mailbox?:string,theme?:string|null} $row
     */
    public function login(array $row): void
    {
        // Signal ContentManager to regenerate the session ID on this request.
        // Prevents session fixation: a session created before login cannot be
        // used after login.
        $_SESSION['_regen_force'] = true;
        $_SESSION[self::LOGGED_IN] = true;
        // A stale password-reset grant must never survive a fresh login (the
        // recovery flow re-sets it AFTER calling login()).
        unset($_SESSION['_pw_reset_until']);
        /** @var array{id:string,username:string,display_name:string,type:int,verified:bool,avatar:bool,mailbox:string,theme:string} $_SESSION */
        $_SESSION[self::KEY] = [
            'id'           => (string)  $row['id'],
            'username'     => (string)  $row['username'],
            'display_name' => (string) $row['display_name'],
            'type'         => (int)     $row['type'],
            'verified'     => (bool)    $row['verified'],
            'avatar'       => (bool)    $row['avatar'],
            'mailbox'      => (string) ($row['mailbox'] ?? ''),
            // Per-user theme override. Empty string = no override = use global.
            'theme'        => (string) ($row['theme']   ?? ''),
        ];
    }

    /**
     * Bootstrap session state for an API request authenticated via Bearer
     * token. Mirrors login() but DOES NOT set `_regen_force`: an API request
     * should never trigger session ID regeneration. The data still lives in
     * $_SESSION so that downstream code (e.g. Gate, controllers) reads it
     * exactly the way it does for web requests — no special-casing needed
     * anywhere else in the framework.
     *
     * @param array{id:string,username:string,display_name:string,type:int,verified:int|bool,avatar:int|bool,mailbox?:string,theme?:string|null} $row
     */
    public function loginFromApiKey(array $row): void
    {
        $_SESSION[self::LOGGED_IN] = true;
        /** @var array{id:string,username:string,display_name:string,type:int,verified:bool,avatar:bool,mailbox:string,theme:string} $_SESSION */
        $_SESSION[self::KEY] = [
            'id'           => (string)  $row['id'],
            'username'     => (string)  $row['username'],
            'display_name' => (string) ($row['display_name'] ?? ''),
            'type'         => (int)     $row['type'],
            'verified'     => (bool)    $row['verified'],
            'avatar'       => (bool)    $row['avatar'],
            'mailbox'      => (string) ($row['mailbox'] ?? ''),
            'theme'        => (string) ($row['theme']   ?? ''),
        ];
    }

    /**
     * The user's personal theme override.
     * Empty string means "no override — use the global theme".
     * Read by ThemeService when resolving the active theme.
     */
    public function userTheme(): string
    {
        $v = $this->sessionData()['theme'] ?? '';
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * Update the user's theme preference live in the current session.
     * Called after UserService::changeTheme() so the new choice takes effect
     * immediately without needing to log out and back in.
     */
    public function updateTheme(string $theme): void
    {
        if (!is_array($_SESSION[self::KEY] ?? null)) { return; }
        /** @var array<string,mixed> $data */
        $data = $_SESSION[self::KEY];
        $data['theme'] = $theme;
        $_SESSION[self::KEY] = $data;
    }

    /** The user's mailbox address (e.g. username@domain.onion). */
    public function mailbox(): string
    {
        $v = $this->sessionData()['mailbox'] ?? ''; return is_scalar($v) ? (string)$v : '';
    }

    /**
     * Store the user's IMAP password in the session, XOR-obfuscated with a
     * key derived from the current session ID.
     *
     * Rationale: even when SecureSessionHandler encryption is disabled, the
     * password is not stored as a legible string. An attacker with only the
     * raw session DB row cannot recover it without also knowing the session ID.
     * An attacker who already has the session ID can read the whole session
     * regardless, so this provides meaningful defence-in-depth against
     * database-only compromise.
     */
    public function storeImapPassword(string $password): void
    {
        if ($password === '') {
            $this->clearImapPassword();
            return;
        }
        $obfuscated = $this->xorWithSessionKey($password);
        $_SESSION['_webmail_pass'] = base64_encode($obfuscated);
    }

    /** Retrieve and de-obfuscate the stored IMAP password, or '' if absent. */
    public function imapPassword(): string
    {
        $stored = $_SESSION['_webmail_pass'] ?? '';
        if (!is_string($stored) || $stored === '') {
            return '';
        }
        $decoded = base64_decode($stored, strict: true);
        if ($decoded === false) {
            return '';
        }
        return $this->xorWithSessionKey($decoded);
    }

    /** Remove the stored IMAP password (called on logout or failed auth). */
    public function clearImapPassword(): void
    {
        unset($_SESSION['_webmail_pass'], $_SESSION['_webmail_key']);
    }

    /** Stable per-session key for obfuscating the stored IMAP password: created
     *  once and kept in the session, so it survives session-ID regeneration
     *  (unlike session_id()). */
    private function webmailKey(): string
    {
        $k = $_SESSION['_webmail_key'] ?? '';
        if (!is_string($k) || $k === '') {
            $k = bin2hex(random_bytes(32));
            $_SESSION['_webmail_key'] = $k;
        }
        return $k;
    }

    /**
     * XOR $data with a keystream derived from a STABLE per-session key (NOT the
     * session ID, which rotates on every regeneration — the old SID-based scheme
     * obfuscated the IMAP password with the login request's SID, then couldn't
     * decode it after the very next request rotated the SID, so the "don't
     * re-prompt for webmail" feature never worked, R3-12). Under the default
     * encrypt=true the whole session (this key included) is AES-encrypted at rest.
     * Applying this twice (encrypt then decrypt) recovers the original.
     */
    private function xorWithSessionKey(string $data): string
    {
        if ($data === '') {
            return $data;
        }
        // Derive a keystream of the same length as $data.
        $key = '';
        $block = hash('sha256', $this->webmailKey(), true); // 32-byte seed block
        $needed = strlen($data);
        for ($i = 0; strlen($key) < $needed; $i++) {
            // 4-byte big-endian counter — equivalent to pack('N', $i) but
            // always returns string so PHPStan is satisfied without stubs.
            $counter  = chr(($i >> 24) & 0xFF) . chr(($i >> 16) & 0xFF)
                      . chr(($i >> 8)  & 0xFF) . chr($i & 0xFF);
            $key .= hash('sha256', $block . $counter, true);
        }
        $key = substr($key, 0, $needed);
        return $data ^ $key;
    }

    public function logout(): void
    {
        $_SESSION['_regen_force'] = true;
        $_SESSION[self::LOGGED_IN] = false;
        unset($_SESSION[self::KEY]);
        unset($_SESSION['_pw_reset_until']); // don't leak a reset grant across sessions
        unset($_SESSION['_remember_until']); // drop the remember-me re-issue window too
        $this->clearImapPassword();
    }

    /** Called after a successful username change. */
    public function updateUsername(string $username): void
    {
        $sess = $_SESSION[self::KEY] ?? null;
        if (!is_array($sess)) { return; }
        /** @var array<string,mixed> $sess */
        $sess['username'] = $username;
        $_SESSION[self::KEY] = $sess;
    }

    /** Called after a successful display_name change. */
    public function updateDisplayName(string $displayName): void
    {
        $sess = $_SESSION[self::KEY] ?? null;
        if (!is_array($sess)) { return; }
        /** @var array<string,mixed> $sess */
        $sess['display_name'] = $displayName;
        $_SESSION[self::KEY] = $sess;
    }

    /** Called after email verification. */
    public function markVerified(): void
    {
        $sess = $_SESSION[self::KEY] ?? null;
        if (!is_array($sess)) { return; }
        /** @var array<string,mixed> $sess */
        $sess['verified'] = true;
        $_SESSION[self::KEY] = $sess;
    }

    /**
     * Called after an admin changes this user's group mid-session.
     * Forces session ID regeneration on the next request from this session.
     */
    public function updateType(int $type): void
    {
        $sess = $_SESSION[self::KEY] ?? null;
        if (!is_array($sess)) { return; }
        /** @var array<string,mixed> $sess */
        $sess['type'] = $type;
        $_SESSION[self::KEY]    = $sess;
        $_SESSION['_regen_force'] = true;
    }

    /** Called after avatar upload / removal. */
    public function updateAvatar(bool $hasAvatar): void
    {
        $sess = $_SESSION[self::KEY] ?? null;
        if (!is_array($sess)) { return; }
        /** @var array<string,mixed> $sess */
        $sess['avatar'] = $hasAvatar;
        $_SESSION[self::KEY] = $sess;
    }

    // ── Logout CSRF token ─────────────────────────────────────────────────────

    private const LOGOUT_TOKEN_KEY = '_logout_token';

    /**
     * Returns the logout CSRF token for the current session, creating it if
     * absent. The token is a 32-byte random hex string used to authenticate
     * GET-based logout links so a malicious page cannot log the user out by
     * embedding an <img> or link.
     */
    public function logoutToken(): string
    {
        $sess = $_SESSION[self::LOGOUT_TOKEN_KEY] ?? null;
        if (!is_string($sess) || $sess === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::LOGOUT_TOKEN_KEY] = $token;
            return $token;
        }
        return $sess;
    }

    /** Consume (clear) the logout token after a successful logout. */
    public function consumeLogoutToken(): void
    {
        unset($_SESSION[self::LOGOUT_TOKEN_KEY]);
    }
}
