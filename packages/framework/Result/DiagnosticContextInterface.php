<?php
declare(strict_types=1);

namespace AstrX\Result;

/**
 * Opt-in structured context for a diagnostic.
 *
 * The JSON API serialises a diagnostic's stable fields (id, level, level_value,
 * and a rendered message) and — ONLY when the diagnostic implements this
 * interface — merges the explicit array returned by context(). This replaces
 * blanket reflection over arbitrary public getters, which risked leaking
 * internal details (file paths, eval text, temp paths) into API responses.
 * Implementers therefore decide exactly what leaves the server.
 *
 * Recognised keys (consumed by JsonRenderer):
 *   - 'http_status' | 'status' (int 100-599): overrides the derived HTTP status.
 *
 * All other keys are exposed verbatim under the response "context" object, so
 * only include values that are safe for an untrusted client to read.
 */
interface DiagnosticContextInterface
{
    /**
     * Explicit, safe-to-expose context payload for API serialisation.
     *
     * @return array<string, mixed>
     */
    public function context(): array;
}
