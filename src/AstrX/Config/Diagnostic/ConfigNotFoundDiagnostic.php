<?php
declare(strict_types=1);

namespace AstrX\Config\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class ConfigNotFoundDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $classShortName,
        private readonly string $configName
    ) {
        parent::__construct($id, $level);
    }

    public function getClassShortName(): string { return $this->classShortName; }
    public function getConfigName(): string     { return $this->configName; }
}