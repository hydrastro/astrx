<?php

declare(strict_types = 1);

namespace AstrX\Chat\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** Message was blocked by the word censor. */
final class ChatCensoredDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
