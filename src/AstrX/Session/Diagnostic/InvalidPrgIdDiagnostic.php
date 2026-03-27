<?php

declare(strict_types = 1);

namespace AstrX\Session\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class InvalidPrgIdDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly ?string $prgId
    ) {
        parent::__construct($id, $level);
    }

    public function prgId()
    : ?string
    {
        return $this->prgId;
    }
}