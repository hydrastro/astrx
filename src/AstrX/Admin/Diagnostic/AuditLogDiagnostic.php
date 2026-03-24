<?php
declare(strict_types=1);

namespace AstrX\Admin\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when writing an audit log entry fails.
 * This should be treated as WARNING — the action succeeded, only the log write failed.
 */
final class AuditLogDiagnostic extends AbstractDiagnostic
{
    public const string          ID    = 'astrx.admin/audit_log_write_failed';
    public const DiagnosticLevel LEVEL = DiagnosticLevel::WARNING;

    public function __construct(
        string $id,
        DiagnosticLevel $level,
        public readonly string $detail = '',
    ) {
        parent::__construct($id, $level);
    }
}