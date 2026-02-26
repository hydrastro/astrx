<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class UncaughtThrowableDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $throwableClass,
        private readonly string $message,
    ) {
        parent::__construct($id, $level);
    }

    public function getThrowableClass(): string { return
        $this->throwableClass; }
    public function getMessage(): string { return $this->message; }
}