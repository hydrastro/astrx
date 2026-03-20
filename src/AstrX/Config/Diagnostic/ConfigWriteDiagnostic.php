<?php

declare(strict_types = 1);

namespace AstrX\Config\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class ConfigWriteDiagnostic extends AbstractDiagnostic
{
    // TODO MOVE THESE OUT.
    public const string ID = 'astrx.config/write_failed';
    public const DiagnosticLevel LEVEL = DiagnosticLevel::ERROR;

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