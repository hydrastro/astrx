<?php

declare(strict_types = 1);

namespace AstrX\Chat\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** Private-message recipient was not found in the chat. */
final class ChatPmTargetDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
