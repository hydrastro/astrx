<?php

declare(strict_types = 1);

namespace AstrX\Http\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class UploadErrorDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $clientFilename,
        private readonly int $errorCode,
    ) {
        parent::__construct($id, $level);
    }

    public function clientFilename()
    : string
    {
        return $this->clientFilename;
    }

    public function errorCode()
    : int
    {
        return $this->errorCode;
    }
}