<?php
declare(strict_types=1);

use AstrX\Admin\Diagnostic\AdminDbDiagnostic;
use AstrX\Admin\Diagnostic\AuditLogDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.admin/db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof AdminDbDiagnostic);
            return 'Admin database error: ' . $d->message();
        },

    'astrx.admin/audit_log_write_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof AuditLogDiagnostic);
            return 'Audit log write failed: ' . $d->detail();
        },
];