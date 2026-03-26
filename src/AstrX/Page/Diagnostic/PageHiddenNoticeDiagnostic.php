<?php
declare(strict_types=1);

namespace AstrX\Page\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when an admin views a page that is hidden from public visitors.
 * This is an informational notice, not an error condition.
 */
final class PageHiddenNoticeDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}