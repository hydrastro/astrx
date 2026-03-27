<?php
declare(strict_types=1);

namespace AstrX\Injector\Diagnostic;

use AstrX\Result\DiagnosticLevel;

final class HelperReflectionDiagnostic extends InjectorDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $className,
        private readonly string $methodName,
        private readonly string $message,
    ) {
        parent::__construct($id, $level);
    }

    public function getClassName(): string { return $this->className; }
    public function getMethodName(): string { return $this->methodName; }
    public function getMessage(): string { return $this->message; }
}
