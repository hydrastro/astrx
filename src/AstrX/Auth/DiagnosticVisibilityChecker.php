<?php

declare(strict_types = 1);

namespace AstrX\Auth;

use AstrX\Result\DiagnosticInterface;
use AstrX\Result\DiagnosticLevel;
use AstrX\User\UserGroup;
use AstrX\User\UserSession;

/**
 * Decides whether the current user may see a given diagnostic.
 * Visibility rules (evaluated in order):
 *   1. ADMIN — always visible. Full access, no DB lookup needed.
 *   2. All other groups — consult diagnostic_visibility table (default deny).
 * Level overrides:
 *   If a diagnostic_level_override row exists for the diagnostic's code,
 *   the stored level replaces the class-declared level for all purposes
 *   (filtering by minLevel AND visibility checks both use the effective level).
 * Usage:
 *   Inject into DiagnosticRenderer via renderFiltered() to filter the
 *   status bar. The checker is loaded lazily — DB queries are deferred
 *   until the first canSee() call.
 */
final class DiagnosticVisibilityChecker
{
    /** @var array<string, list<string>>|null  code → list of group_names that can see it */
    private ?array $visibilityMap = null;
    /** @var array<string, DiagnosticLevel>|null  code → override level */
    private ?array $levelOverrides = null;

    public function __construct(
        private readonly UserSession $session,
        private readonly DiagnosticVisibilityRepository $visibilityRepo,
        private readonly DiagnosticLevelOverrideRepository $levelRepo,
    ) {
    }

    /**
     * Returns true if the current user may see this diagnostic.
     * Also applies any level override stored in the DB before checking.
     */
    public function canSee(DiagnosticInterface $diagnostic)
    : bool {
        // ADMIN has full access — short-circuit before any DB hit.
        if ($this->session->isAdmin()) {
            return true;
        }

        $code = $diagnostic->id();
        $this->ensureLoaded();

        $groups = $this->visibilityMap[$code]??[];
        if ($groups === []) {
            return false; // default deny
        }

        $groupName = $this->session->isLoggedIn() ?
            $this->session->userType()->name : UserGroup::GUEST->name;

        return in_array($groupName, $groups, true);
    }

    /**
     * Return the effective level for a diagnostic, applying any DB override.
     * Used by DiagnosticRenderer to honour level overrides before filtering.
     */
    public function effectiveLevel(DiagnosticInterface $diagnostic)
    : DiagnosticLevel {
        $this->ensureLoaded();

        return $this->levelOverrides[$diagnostic->id()]??$diagnostic->level();
    }

    // ── Lazy loading ──────────────────────────────────────────────────────────

    private function ensureLoaded()
    : void
    {
        if ($this->visibilityMap === null) {
            $r = $this->visibilityRepo->all();
            $this->visibilityMap = $r->isOk() ? $r->unwrap() : [];
        }
        if ($this->levelOverrides === null) {
            $r = $this->levelRepo->all();
            $this->levelOverrides = $r->isOk() ? $r->unwrap() : [];
        }
    }
}