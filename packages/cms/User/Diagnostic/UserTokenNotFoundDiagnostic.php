<?php
declare(strict_types=1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** Token id not found or already consumed. */
final class UserTokenNotFoundDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }

}