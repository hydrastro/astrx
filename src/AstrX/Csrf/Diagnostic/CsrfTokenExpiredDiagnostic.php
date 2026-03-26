<?php

declare(strict_types = 1);

namespace AstrX\Csrf\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a CSRF token was found and matched but has passed its expiry time.
 * The user likely left the form open for too long before submitting.
 */
final class CsrfTokenExpiredDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $formId,
        private readonly int $expiredAt,
    ) {
        parent::__construct($id, $level);
    }

    public function formId()
    : string
    {
        return $this->formId;
    }

    public function expiredAt()
    : int
    {
        return $this->expiredAt;
    }

}