<?php

declare(strict_types = 1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * One of the identifiers a registration / settings change asked for is already
 * in use — deliberately WITHOUT saying which.
 *
 * Replaces the separate username_taken / email_taken / mailbox_taken
 * diagnostics. Those three answered, for any address an attacker cared to type
 * into the public registration form, the question "does this person have an
 * account on this hidden service?" — the exact oracle RecoverController goes to
 * some trouble to avoid (it mirrors the success path byte for byte for an
 * unknown user). Registration handed the same answer out for free.
 *
 * The rendered message names every field that could be at fault, so a genuine
 * registrant still knows what to change.
 */
final class UserIdentifierUnavailableDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
