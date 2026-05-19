<?php
declare(strict_types=1);

namespace AstrX\Mail\Diagnostic;

use AstrX\Result\AbstractDiagnostic;

/**
 * Emitted when the framework would send an email but no mailer is wired.
 * Used by RegisterController when generating verification tokens that should
 * have been emailed.
 *
 * Visible to admins in the diagnostics panel so pending verifications are
 * not silently lost. Replace with a working mailer integration to silence.
 */
final class MailerNotConfiguredDiagnostic extends AbstractDiagnostic
{
}
