<?php
declare(strict_types=1);

namespace AstrX\Api\Diagnostic;

use AstrX\Result\AbstractDiagnostic;

/**
 * Emitted when an /api/ request resolves to a page that doesn\'t have
 * `api_enabled = 1`. The response is a 404 — we don\'t reveal that the
 * page exists for non-API consumption.
 */
final class ApiNotEnabledDiagnostic extends AbstractDiagnostic
{
}
