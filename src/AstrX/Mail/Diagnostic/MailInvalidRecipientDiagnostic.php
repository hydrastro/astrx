<?php

declare(strict_types = 1);

namespace AstrX\Mail\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * A recipient/sender address, display name, or subject failed header-safety
 * (no CR/LF/NUL) or address-shape validation before the SMTP dialogue or the
 * raw-message composition began. Raised to prevent SMTP command / mail header
 * injection.
 *
 * detail = the offending value — for logs only. It is deliberately NOT echoed
 * to end users (the translation key renders a generic message).
 */
final class MailInvalidRecipientDiagnostic extends AbstractDiagnostic
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
