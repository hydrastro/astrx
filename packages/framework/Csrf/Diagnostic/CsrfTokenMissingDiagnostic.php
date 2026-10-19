<?php
declare(strict_types=1);

namespace AstrX\Csrf\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a POST request arrives with no CSRF token field at all.
 * Distinct from a mismatch: the field was simply absent.
 */
final class CsrfTokenMissingDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $formId,
    ) {
        parent::__construct($id, $level);
    }

    public function formId(): string { return $this->formId; }

}