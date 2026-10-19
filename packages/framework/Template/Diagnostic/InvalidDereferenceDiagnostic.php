<?php
declare(strict_types=1);

namespace AstrX\Template\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class InvalidDereferenceDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $value,
    ) {
        parent::__construct($id, $level);
    }

    public function value(): string { return $this->value; }
}