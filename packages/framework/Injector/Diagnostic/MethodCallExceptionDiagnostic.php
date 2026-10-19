<?php
declare(strict_types=1);

namespace AstrX\Injector\Diagnostic;

use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a method called via Injector::callClassMethod() throws a
 * Throwable at runtime. This is distinct from ClassReflectionDiagnostic,
 * which covers reflection failures (class/method not found at analysis time).
 */
final class MethodCallExceptionDiagnostic extends InjectorDiagnostic
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

    public function getClassName(): string  { return $this->className; }
    public function getMethodName(): string { return $this->methodName; }
    public function getMessage(): string    { return $this->message; }
}
