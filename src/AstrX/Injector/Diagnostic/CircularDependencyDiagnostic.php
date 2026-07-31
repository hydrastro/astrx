<?php
declare(strict_types=1);

namespace AstrX\Injector\Diagnostic;

use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when the injector detects a constructor dependency cycle — a class
 * that (transitively) depends on itself. Without this the recursive resolver
 * would recurse until a stack-overflow fatal; instead a clean error Result is
 * returned carrying the class the cycle was detected on.
 */
final class CircularDependencyDiagnostic extends InjectorDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $className,
    ) {
        parent::__construct($id, $level);
    }

    public function getClassName(): string { return $this->className; }
}
