<?php

declare(strict_types = 1);

namespace AstrX\Http\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class MoveFailedDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $destination,
    ) {
        parent::__construct($id, $level);
    }

    public function destination()
    : string
    {
        return $this->destination;
    }
}