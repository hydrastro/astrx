<?php
declare(strict_types=1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;

/**
 * Emitted when a user attempts to set their theme to a name that is not
 * installed (either because they tampered with the form or because the
 * theme was removed since they saw the picker). Render with a friendly
 * "that theme is not installed" message and let them try again.
 */
final class InvalidThemeDiagnostic extends AbstractDiagnostic
{
}
