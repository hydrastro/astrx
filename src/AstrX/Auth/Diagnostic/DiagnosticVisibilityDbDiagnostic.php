<?php
declare(strict_types=1);

namespace AstrX\Auth\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** PDO error in the diagnostic_visibility or diagnostic_level_override tables. */
final class DiagnosticVisibilityDbDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $detail = '',
    ) {
        parent::__construct($id, $level);
    }

    public function detail(): string { return $this->detail; }
}