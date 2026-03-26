<?php
declare(strict_types=1);

namespace AstrX\Mail\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** STARTTLS negotiation failed. detail = reason. */
final class ImapStartTlsDiagnostic extends AbstractDiagnostic
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