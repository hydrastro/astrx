<?php
declare(strict_types=1);

namespace AstrX\Injector\Diagnostic;

use AstrX\Result\DiagnosticLevel;

final class MethodNotFoundDiagnostic extends InjectorDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $className,
        private readonly string $methodName,
    ) {
        parent::__construct($id, $level);
    }

    public function getClassName(): string { return $this->className; }
    public function getMethodName(): string { return $this->methodName; }
}
