<?php
declare(strict_types=1);

namespace AstrX\Result;

interface DiagnosticSinkInterface
{
    public function emit(DiagnosticInterface $d): void;
    public function emitAll(Diagnostics $diagnostics): void;
}
