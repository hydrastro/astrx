<?php

declare(strict_types = 1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** Mailbox local-part already assigned to another account. */
final class UserMailboxTakenDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }

}