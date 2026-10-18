<?php
declare(strict_types=1);

namespace AstrX\Auth;

/**
 * The caller, as the authorization layer sees it.
 *
 * Gate, policies and DiagnosticVisibilityChecker depend on this contract
 * instead of the concrete account session, so the security layer does not
 * import the accounts layer (AstrX\User). The accounts layer supplies the
 * implementation (UserSession) and the composition root binds it.
 *
 * Keep it narrow: only what an authorization decision needs. Anything richer
 * (display name, avatar, mailbox …) stays on the concrete session.
 */
interface Identity
{
    /** Role name used for a caller that is not logged in. */
    public const GUEST_ROLE = 'GUEST';

    /** Whether the caller has authenticated. */
    public function isLoggedIn(): bool;

    /** Stable caller id, or '' when there is none. */
    public function userId(): string;

    /**
     * The role this identity carries, as named in the `grants` map
     * (e.g. 'ADMIN', 'MOD', 'USER', 'GUEST').
     *
     * This is the raw role, NOT gated on isLoggedIn(): Gate and
     * DiagnosticVisibilityChecker apply "logged out ⇒ GUEST_ROLE" themselves,
     * while resource policies compare the raw role exactly as they always have.
     */
    public function roleName(): string;
}
