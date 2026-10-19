<?php

declare(strict_types = 1);

namespace AstrX\Chat\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** Chosen nickname is taken or reserved by a member. */
final class ChatNickTakenDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
