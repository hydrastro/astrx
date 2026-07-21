<?php
declare(strict_types=1);

use AstrX\Admin\Diagnostic\AuditLogDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    // Messaggio generico e sicuro: l'errore grezzo del driver resta solo nei log.
    'astrx.admin/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
            'Si è verificato un errore del database. Riprova più tardi.',
    // Generico e id-rendered: mai interpolare $d->detail() (testo grezzo dell'eccezione/driver).
    'astrx.admin/audit_log_write_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof AuditLogDiagnostic);
            return 'Scrittura del registro di audit non riuscita (ID: ' . $d->id() . ').';
        },
];
