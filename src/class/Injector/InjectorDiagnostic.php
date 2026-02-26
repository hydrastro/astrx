<?php
declare(strict_types=1);

namespace AstrX\Injector;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

abstract class InjectorDiagnostic extends AbstractDiagnostic
{
    // No defaults here by design.
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
