<?php
declare(strict_types=1);

namespace AstrX\Config;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class ConfigFileInvalidDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $file
    ) {
        parent::__construct($id, $level);
    }

    public function getFile(): string { return $this->file; }
}