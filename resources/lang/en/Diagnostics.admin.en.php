<?php
declare(strict_types=1);

use AstrX\Admin\Diagnostic\AdminDbDiagnostic;
use AstrX\Admin\Diagnostic\AdminDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.admin/db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof AdminDbDiagnostic);
            return "Database error: " . $d->message();
        },
    'astrx.admin/operation' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof AdminDiagnostic);
            return "Admin error: " . $d->operation();
        },
];
