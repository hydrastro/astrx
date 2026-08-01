<?php
declare(strict_types=1);

namespace AstrX\Page\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a `page` row declares controller=1 but the controller class its
 * file_name resolves to does not exist — a seed/code mismatch (e.g. a config
 * section seeded in tables.sql whose controller was never implemented). The page
 * is served as a themed 404 instead of a raw 500 so the misconfiguration degrades
 * gracefully; this diagnostic keeps it visible to operators in the logs.
 */
final class ControllerMissingDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
