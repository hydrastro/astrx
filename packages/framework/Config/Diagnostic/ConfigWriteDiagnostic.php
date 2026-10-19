<?php

declare(strict_types = 1);

namespace AstrX\Config\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class ConfigWriteDiagnostic extends AbstractDiagnostic
{

    public function __construct(
        string $id,
        DiagnosticLevel $level,
        public readonly string $path,
        public readonly string $reason,
    ) {
        parent::__construct($id, $level);
    }

    public function message()
    : string
    {
        return "Config write failed ({$this->reason}): {$this->path}";
    }
}