<?php
declare(strict_types=1);

namespace AstrX\Injector;

use AstrX\Result\DiagnosticLevel;

final class UnresolvableParameterDiagnostic extends InjectorDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $className,
        private readonly string $parameterName,
    ) {
        parent::__construct($id, $level);
    }

    public function getClassName(): string { return $this->className; }
    public function getParameterName(): string { return $this->parameterName; }
}
