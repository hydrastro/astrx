<?php

declare(strict_types = 1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Password is shorter than the hard minimum UserService enforces in code.
 *
 * Separate from UserInvalidPasswordDiagnostic (which carries an
 * operator-written rule message from UserService.password_regex) because this
 * one is produced by the code floor that exists precisely for when that config
 * array is empty — so its text has to be translated, not configured.
 */
final class UserPasswordTooShortDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly int $minLength = 0,
    ) {
        parent::__construct($id, $level);
    }

    public function minLength(): int { return $this->minLength; }
}
