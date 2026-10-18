<?php
declare(strict_types=1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;

/**
 * Emitted when EmailService can't render any body for a given email kind
 * (both HTML and text templates failed to load or compile).
 */
final class EmailTemplateMissingDiagnostic extends AbstractDiagnostic
{
}
