<?php

declare(strict_types = 1);

namespace AstrX\Http\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class NotAnUploadedFileDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $tempPath,
    ) {
        parent::__construct($id, $level);
    }

    public function tempPath()
    : string
    {
        return $this->tempPath;
    }
}