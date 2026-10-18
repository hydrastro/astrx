<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Http\Request;
use AstrX\Injector\Injector;
use AstrX\Result\DiagnosticsCollector;

/**
 * Establishes who is calling, once the session is running.
 *
 *   - A bearer (API-key) request is authenticated by its key; a valid key for
 *     an open, undeleted account logs that account into this request's
 *     (stateless) session.
 *   - A cookie session of a logged-in user is re-validated against the
 *     database on every request (R11): a closed or deleted account is logged
 *     out, a changed role applies immediately, and a bumped session epoch
 *     evicts the session (R13).
 */
final class RequestIdentity
{
    public function __construct(
        private readonly Injector             $injector,
        private readonly DiagnosticsCollector $collector,
    ) {}

    /**
     * @return bool false when the account store could not be consulted; the
     *              caller must refuse the request (503) — the session is kept,
     *              so the request can be retried.
     */
    public function establish(Request $request, bool $bearerPresent): bool
    {
        // ── Bearer / API-key authentication ───────────────────────────────────
        // Must run AFTER session_start so the identity survives, and after
        // regeneration so a stateless API call doesn't rotate a guest session.
        // loginFromApiKey() omits _regen_force, so it triggers no further regen.
        $bearer = $request->bearerToken();
        if ($bearer !== null && $bearer !== '') {
            $apiKeyResult = $this->injector->createClass(\AstrX\Api\ApiKeyService::class)
                ->drainTo($this->collector);
            if ($apiKeyResult->isOk()) {
                /** @var \AstrX\Api\ApiKeyService $apiKeySvc */
                $apiKeySvc = $apiKeyResult->unwrap();
                $authedUserId = $apiKeySvc->validate($bearer);
                if ($authedUserId !== null) {
                    $request->setApiKeyUser($authedUserId);
                    $userRepoResult = $this->injector->createClass(\AstrX\User\UserRepository::class)
                        ->drainTo($this->collector);
                    $userSessResult = $this->injector->createClass(\AstrX\User\UserSession::class)
                        ->drainTo($this->collector);
                    if ($userRepoResult->isOk() && $userSessResult->isOk()) {
                        /** @var \AstrX\User\UserRepository $userRepo */
                        $userRepo = $userRepoResult->unwrap();
                        /** @var \AstrX\User\UserSession $userSess */
                        $userSess = $userSessResult->unwrap();
                        $userRow = $userRepo->findById($authedUserId);
                        $userRow->drainTo($this->collector);
                        if ($userRow->isOk()) {
                            $row = $userRow->unwrap();
                            // A closed account (keep_visible keeps deleted=0 for
                            // content visibility) must not authenticate via its API
                            // key either — password login already blocks non-'none'
                            // deletion_mode; mirror that on the bearer path so a
                            // key can't outlive the account being closed.
                            $delMode = (is_array($row) && is_scalar($row['deletion_mode'] ?? null))
                                ? (string) $row['deletion_mode'] : '';
                            $accountOpen = ($delMode === '' || $delMode === 'none');
                            if (is_array($row) && !empty($row['id']) && empty($row['deleted']) && $accountOpen) {
                                /** @var array{id:string,username:string,display_name:string,type:int,verified:int|bool,avatar:int|bool,mailbox?:string,theme?:string|null} $row */
                                $userSess->loginFromApiKey($row);
                            }
                        }
                    }
                }
            }
        }

        // ── Cookie-session re-validation (R11, MED) ───────────────────────────
        // The Gate resolves a request's role from SESSION state, written once at
        // login and never re-checked against the DB. Without this, an admin action
        // that demotes, bans, or deletes a user does NOT take effect until that
        // user logs out or the session expires (up to the remember-me lifetime) —
        // so a rogue/compromised privileged account can't be evicted, which is a
        // core incident-response need. Re-load the row per request for a logged-in
        // COOKIE session (the bearer path did its own check above): drop the
        // session if the account is now closed/deleted; otherwise refresh the
        // cached group so a role change applies immediately. On a DB error refuse
        // this request without deleting the session, so it can be retried.
        if (!$bearerPresent) {
            $revSessRes = $this->injector->createClass(\AstrX\User\UserSession::class)->drainTo($this->collector);
            $revRepoRes = $this->injector->createClass(\AstrX\User\UserRepository::class)->drainTo($this->collector);
            if (!$revSessRes->isOk() || !$revRepoRes->isOk()) {
                return false;
            }

            /** @var \AstrX\User\UserSession $revSess */
            $revSess = $revSessRes->unwrap();
            /** @var \AstrX\User\UserRepository $revRepo */
            $revRepo = $revRepoRes->unwrap();
            if ($revSess->isLoggedIn() && $revSess->userId() !== '') {
                $rowRes = $revRepo->findById($revSess->userId());
                $rowRes->drainTo($this->collector);
                if (!$rowRes->isOk()) {
                    return false;
                }

                $row = $rowRes->unwrap();
                if (!is_array($row)) {
                    $revSess->logout();   // row vanished → session no longer valid
                } else {
                    $delMode     = is_scalar($row['deletion_mode'] ?? null) ? (string) $row['deletion_mode'] : '';
                    $accountOpen = ($delMode === '' || $delMode === 'none');
                    if (!empty($row['deleted']) || !$accountOpen) {
                        $revSess->logout();
                    } else {
                        if (is_scalar($row['type'] ?? null) && (int) $row['type'] !== $revSess->userType()->value) {
                            $revSess->refreshType((int) $row['type']);
                        }
                        // R13: session-epoch force-logout. Adopt the DB
                        // epoch on the first re-validation after login; a
                        // later admin bump (evict-everywhere) then no longer
                        // matches, so the session is dropped on its next
                        // request — instant eviction of a compromised account.
                        // Known narrow window: a bump landing between login
                        // and this FIRST check is absorbed (adopted). An
                        // actively-used stolen session has already adopted
                        // and is evicted correctly; deleting/banning the
                        // account evicts regardless via the check above.
                        $dbEpoch   = is_scalar($row['session_epoch'] ?? null) ? (int) $row['session_epoch'] : 0;
                        $sessEpoch = $revSess->sessionEpoch();
                        if ($sessEpoch === null) {
                            $revSess->setSessionEpoch($dbEpoch);
                        } elseif ($sessEpoch !== $dbEpoch) {
                            $revSess->logout();
                        }
                    }
                }
            }
        }

        return true;
    }
}
