<?php
declare(strict_types=1);

use AstrX\Admin\Diagnostic\AuditLogDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    // Generic, user-safe message. The diagnostic still carries the raw driver
    // error for server-side logs; it is deliberately not rendered to the client.
    'astrx.admin/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'A database error occurred. Please try again later.',

    'astrx.admin/audit_log_write_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof AuditLogDiagnostic);
            return 'Audit log write failed: ' . $d->detail();
        },

    // Auth — diagnostic visibility DB errors
    'astrx.auth/diag_visibility.db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof \AstrX\Admin\Diagnostic\DiagnosticVisibilityDbDiagnostic);
            return 'Diagnostic visibility DB error: ' . $d->detail();
        },

    'astrx.auth/diag_level_override.db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof \AstrX\Admin\Diagnostic\DiagnosticVisibilityDbDiagnostic);
            return 'Diagnostic level override DB error: ' . $d->detail();
        },
];