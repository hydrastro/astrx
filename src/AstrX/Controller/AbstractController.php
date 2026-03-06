<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\Result\DiagnosticInterface;
use AstrX\Result\DiagnosticsCollector;

abstract class AbstractController implements Controller
{
    public function __construct(protected DiagnosticsCollector $collector)
    {
    }

    final protected function ok()
    : Result
    {
        return Result::ok(null);
    }

    final protected function err(mixed $error = null, ?Diagnostics $d = null)
    : Result {
        return Result::err($error, $d);
    }

    final protected function emit(DiagnosticInterface $d)
    : void {
        $this->collector->emit($d);
    }
}