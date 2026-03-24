<?php
declare(strict_types=1);

namespace AstrX\Mail\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Diagnostic for mail subsystem failures.
 *
 * Operation slugs (used as sub-key in the translation ID):
 *   send_failed          SMTP transmission failed
 *   connect_failed       Cannot reach SMTP server
 *   auth_failed          SMTP AUTH rejected
 *   mailbox_error        Dovecot mailbox operation failed
 *   mailapi_error        Management API returned an error
 *   mailapi_unreachable  Cannot reach management API
 *   write_failed         Direct file write to passwd/userdb failed
 *   invalid_payload      Unparseable JSON payload
 *
 * ID and level constants are owned by the emitting class (Mailer, MailboxManager).
 * This class only carries the operation and detail for rendering.
 */
final class MailDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        public readonly string $operation,
        public readonly string $detail = '',
    ) {
        parent::__construct($id, $level);
    }

    public function translationKey(): string
    {
        return 'astrx.mail.' . $this->operation;
    }

    public function context(): array
    {
        return ['detail' => $this->detail];
    }
}