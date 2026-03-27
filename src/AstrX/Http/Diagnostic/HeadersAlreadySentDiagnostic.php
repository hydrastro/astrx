<?php

declare(strict_types = 1);

namespace AstrX\Http\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class HeadersAlreadySentDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $file,
        private readonly int $line,
    ) {
        parent::__construct($id, $level);
    }

    public function file()
    : string
    {
        return $this->file;
    }

    public function line()
    : int
    {
        return $this->line;
    }
}