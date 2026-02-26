<?php
declare(strict_types=1);

namespace AstrX\Injector\Diagnostic;

use AstrX\Result\DiagnosticLevel;

final class ClassReflectionDiagnostic extends InjectorDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $message,
    ) {
        parent::__construct($id, $level);
    }

    public function getMessage(): string { return $this->message; }
}
