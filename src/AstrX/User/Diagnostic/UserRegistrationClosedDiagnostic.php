<?php
declare(strict_types=1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** Registrations are currently disabled. */
final class UserRegistrationClosedDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }

}