<?php
declare(strict_types=1);

namespace AstrX\Api\Diagnostic;

use AstrX\Result\AbstractDiagnostic;

/**
 * Emitted when API auth fails or an API key operation cannot be completed.
 *
 * Used uniformly for ALL auth-failure modes (no matching key, revoked,
 * expired, malformed) so the network observer cannot distinguish which
 * one. The framework knows the difference internally; the API caller
 * just sees "401 Unauthorized".
 */
final class InvalidApiKeyDiagnostic extends AbstractDiagnostic
{
}
