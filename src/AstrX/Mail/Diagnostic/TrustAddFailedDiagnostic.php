<?php

declare(strict_types = 1);

namespace AstrX\Mail\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** PDO error while adding a trusted sender. detail = exception message. */
final class TrustAddFailedDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        public readonly string $detail = '',
    ) {
        parent::__construct($id, $level);
    }

    public function vars()
    : array
    {
        return ['detail' => $this->detail];
    }
}