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
     * Store the user's IMAP password in the (AES-encrypted) session.
     * Called at login time so webmail can connect without re-prompting.
     * Safe: the session data is AES-256-CTR encrypted at rest.
     */
    public function storeImapPassword(string $password): void
    {
        $_SESSION['_webmail_pass'] = $password;
    }

    /** Retrieve the stored IMAP password, or '' if not set. */
    public function imapPassword(): string
    {
        $pw = $_SESSION['_webmail_pass'] ?? ''; return is_string($pw) ? $pw : '';
    }

    /** Remove the stored IMAP password (on logout or failed auth). */
    public function clearImapPassword(): void
    {
        unset($_SESSION['_webmail_pass']);
    }

    public function logout(): void
    {
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

    /** Called after avatar upload / removal. */
    public function updateAvatar(bool $hasAvatar): void
    {
        $sess = $_SESSION[self::KEY] ?? null;
        if (!is_array($sess)) { return; }
        /** @var array<string,mixed> $sess */
        $sess['avatar'] = $hasAvatar;
        $_SESSION[self::KEY] = $sess;
    }
}
