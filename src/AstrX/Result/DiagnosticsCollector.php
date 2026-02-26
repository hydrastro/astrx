<?php
declare(strict_types=1);

namespace AstrX\Result;

final class DiagnosticsCollector implements DiagnosticSinkInterface
{
    private Diagnostics $diagnostics;

    public function __construct(?Diagnostics $initial = null)
    {
        $this->diagnostics = $initial ?? Diagnostics::empty();
    }

    public function emit(DiagnosticInterface $d): void
    {
        $this->diagnostics = $this->diagnostics->with($d);
    }

    public function emitAll(Diagnostics $diagnostics): void
    {
        $this->diagnostics = $this->diagnostics->concat($diagnostics);
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
