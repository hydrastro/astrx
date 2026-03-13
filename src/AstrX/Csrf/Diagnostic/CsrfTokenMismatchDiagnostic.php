<?php

declare(strict_types = 1);

namespace AstrX\Csrf\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a CSRF token is present but does not match the session value.
 * May indicate a genuine attack, a stale form, or a session boundary crossing.
 */
final class CsrfTokenMismatchDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $formId,
    ) {
        parent::__construct($id, $level);
    }

    public function formId()
    : string
    {
        return $this->formId;
    }

    public function vars()
    : array
    {
        return ['form_id' => $this->formId];
    }
}