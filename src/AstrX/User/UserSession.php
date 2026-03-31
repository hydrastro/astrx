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
     * @param array{id:string,username:string,display_name:string,type:int,verified:int|bool,avatar:int|bool,mailbox?:string} $row
     */
    public function login(array $row): void
    {
        // Signal ContentManager to regenerate the session ID on this request.
        // Prevents session fixation: a session created before login cannot be
        // used after login.
        $_SESSION['_regen_force'] = true;
        $_SESSION[self::LOGGED_IN] = true;
        /** @var array{id:string,username:string,display_name:string,type:int,verified:bool,avatar:bool,mailbox:string} $_SESSION */
        $_SESSION[self::KEY] = [
            'id'           => (string)  $row['id'],
            'username'     => (string)  $row['username'],
            'display_name' => (string) $row['display_name'],
            'type'         => (int)     $row['type'],
            'verified'     => (bool)    $row['verified'],
            'avatar'       => (bool)    $row['avatar'],
            'mailbox'      => (string) ($row['mailbox'] ?? ''),
        ];
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
        unset($_SESSION['_webmail_pass']);
    }

    /**
     * XOR $data with a keystream derived from the current session ID.
     * Applying this twice (encrypt then decrypt) recovers the original.
     */
    private function xorWithSessionKey(string $data): string
    {
        $sid = session_id();
        if ($sid === false || $sid === '' || $data === '') {
            return $data;
        }
        // Derive a keystream of the same length as $data.
        $key = '';
        $block = hash('sha256', $sid, true); // 32-byte seed block
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
