<?php
declare(strict_types=1);

namespace AstrX\Result;

/**
 * Decides which diagnostics a viewer may see, and at what level.
 *
 * Rendering (I18n\DiagnosticRenderer) takes this contract instead of a concrete
 * checker, so the diagnostics layer never imports the security or accounts
 * layers. The application supplies the implementation
 * (Admin\DiagnosticVisibilityChecker, backed by the admin-editable tables).
 */
interface DiagnosticFilter
{
    /** Whether the current viewer may see this diagnostic. */
    public function canSee(DiagnosticInterface $diagnostic): bool;

    /** The level to use for this diagnostic (an override, or its own level()). */
    public function effectiveLevel(DiagnosticInterface $diagnostic): DiagnosticLevel;
}
