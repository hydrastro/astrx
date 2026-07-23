<?php

declare(strict_types = 1);

namespace AstrX\Chat\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** The guest entry password was missing or wrong. */
final class ChatEntryPasswordDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
