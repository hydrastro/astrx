<?php
declare(strict_types=1);

namespace AstrX\Injector\Diagnostic;

use AstrX\Result\DiagnosticLevel;

final class ClassNotFoundDiagnostic extends InjectorDiagnostic
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
