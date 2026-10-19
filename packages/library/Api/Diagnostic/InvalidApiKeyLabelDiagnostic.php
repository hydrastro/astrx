<?php
declare(strict_types=1);

namespace AstrX\Api\Diagnostic;

use AstrX\Result\AbstractDiagnostic;

/**
 * Emitted when a user submits the "create API key" form without a label,
 * or with a label that fails validation.
 */
final class InvalidApiKeyLabelDiagnostic extends AbstractDiagnostic
{
}
