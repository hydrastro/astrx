<?php

declare(strict_types = 1);

namespace AstrX\Http\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class InvalidParameterTypeDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $key,
        private readonly string $expectedType,
    ) {
        parent::__construct($id, $level);
    }

    public function key()
    : string
    {
        return $this->key;
    }

    public function expectedType()
    : string
    {
        return $this->expectedType;
    }
}