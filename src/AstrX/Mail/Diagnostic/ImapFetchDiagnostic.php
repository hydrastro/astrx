<?php

declare(strict_types = 1);

namespace AstrX\Mail\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** UID FETCH returned empty or missing literal. detail = uid. */
final class ImapFetchDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $detail = '',
    ) {
        parent::__construct($id, $level);
    }


    public function detail(): string { return $this->detail; }
}