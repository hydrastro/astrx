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

    public function userId(): string
    {
        return (string) (($_SESSION[self::KEY] ?? [])['id'] ?? '');
    }

    public function username(): string
    {
        return (string) (($_SESSION[self::KEY] ?? [])['username'] ?? '');
    }

    public function displayName(): string
    {
        return (string) (($_SESSION[self::KEY] ?? [])['display_name'] ?? '');
    }

    public function userType(): UserGroup
    {
        $raw = (int) (($_SESSION[self::KEY] ?? [])['type'] ?? UserGroup::GUEST->value);
        return UserGroup::tryFrom($raw) ?? UserGroup::GUEST;
    }

    public function isVerified(): bool
    {
        return (bool) (($_SESSION[self::KEY] ?? [])['verified'] ?? false);
    }

    public function hasAvatar(): bool
    {
        return (bool) (($_SESSION[self::KEY] ?? [])['avatar'] ?? false);
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
        return (string) (($_SESSION[self::KEY] ?? [])['mailbox'] ?? '');
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
        return (string) ($_SESSION['_webmail_pass'] ?? '');
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
        if (isset($_SESSION[self::KEY])) {
            $_SESSION[self::KEY]['username'] = $username;
        }
    }

    /** Called after a successful display_name change. */
    public function updateDisplayName(string $displayName): void
    {
        if (isset($_SESSION[self::KEY])) {
            $_SESSION[self::KEY]['display_name'] = $displayName;
        }
    }

    /** Called after email verification. */
    public function markVerified(): void
    {
        if (isset($_SESSION[self::KEY])) {
            $_SESSION[self::KEY]['verified'] = true;
        }
    }

    /** Called after avatar upload / removal. */
    public function updateAvatar(bool $hasAvatar): void
    {
        if (isset($_SESSION[self::KEY])) {
            $_SESSION[self::KEY]['avatar'] = $hasAvatar;
        }
    }
}
