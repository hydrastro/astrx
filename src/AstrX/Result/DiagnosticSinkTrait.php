<?php
declare(strict_types=1);

namespace AstrX\Result;

trait DiagnosticSinkTrait
{
    private ?DiagnosticSinkInterface $diagnosticSink = null;

    final public function setDiagnosticSink(DiagnosticSinkInterface $sink): void
    {
        $this->diagnosticSink = $sink;
    }

    final protected function emit(DiagnosticInterface $d): void
    {
        if ($this->diagnosticSink === null) {
            throw new SinkNotFoundDiagnostic(static::class);
        }
        $this->diagnosticSink->emit($d);
    }

    final protected function emitAll(Diagnostics $d): void
    {
        if ($this->diagnosticSink === null) {
            throw new SinkNotFoundDiagnostic(static::class);
        }
        $this->diagnosticSink->emitAll($d);
    }
}
