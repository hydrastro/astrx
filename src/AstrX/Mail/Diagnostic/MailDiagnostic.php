<?php

declare(strict_types = 1);

namespace AstrX\Mail\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Diagnostic for mail subsystem failures.
 * Operation slugs:
 *   send_failed       — SMTP transmission failed
 *   connect_failed    — cannot reach SMTP server
 *   auth_failed       — SMTP AUTH rejected
 *   mailbox_error     — Dovecot mailbox operation failed
 *   mailapi_error     — Management API returned an error
 *   mailapi_unreachable — Cannot reach management API
 */
final class MailDiagnostic extends AbstractDiagnostic
{
    public const string ID = 'astrx.mail/error';
    public const DiagnosticLevel LEVEL = DiagnosticLevel::ERROR;

    public function __construct(string $operation, string $detail = '')
    {
        parent::__construct(
            self::ID,
            self::LEVEL,
            $operation . ($detail !== '' ? ': ' . $detail : ''),
        );
    }
}