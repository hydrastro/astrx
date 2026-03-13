<?php

declare(strict_types = 1);

namespace AstrX\Http\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class UnknownMethodDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $raw,
    ) {
        parent::__construct($id, $level);
    }

    public function raw()
    : string
    {
        return $this->raw;
    }
}