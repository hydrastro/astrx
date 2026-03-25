<?php

declare(strict_types = 1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** Username fails regex validation. detail = rule message. */
final class UserInvalidUsernameDiagnostic extends AbstractDiagnostic
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