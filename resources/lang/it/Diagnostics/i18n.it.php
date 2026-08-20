<?php

declare(strict_types = 1);

use AstrX\I18n\Diagnostic\LangWriteDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostiche dell'editor delle lingue (AstrX\I18n\LangCatalog) — locale it.
 *
 * Nessuna delle dieci aveva una voce di catalogo in alcuna lingua: l'editor
 * mostrava il timbro grezzo — ad esempio, salvando una traduzione con
 * resources/lang/ in sola lettura (lo stato lasciato da secure-config.sh)
 * compariva letteralmente "[FALLBACK:ERROR] astrx.i18n/lang_write_failed".
 *
 * LangWriteDiagnostic::message() contiene il percorso interessato: è un percorso
 * del filesystem del server, mostrato perché questa superficie è riservata agli
 * amministratori ed è esattamente ciò su cui l'operatore deve intervenire.
 */
return [
    'astrx.i18n/lang_write_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Impossibile salvare il file di traduzione: ' . $d->message()
                . ' Nulla è stato scritto — verifica che resources/lang/ sia scrivibile.';
        },

    'astrx.i18n/lang_domain_invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Dominio di traduzione non valido: ' . $d->message();
        },

    'astrx.i18n/lang_not_editable' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return "Questo file di traduzione non è modificabile dall'interfaccia di amministrazione: "
                . $d->message();
        },

    'astrx.i18n/lang_code_invalid' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Codice lingua non valido: ' . $d->message();
        },

    'astrx.i18n/lang_exists' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Questa lingua esiste già: ' . $d->message();
        },

    'astrx.i18n/lang_source_missing' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Lingua di origine non trovata: ' . $d->message();
        },

    'astrx.i18n/lang_primary_protected' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'La lingua principale non può essere eliminata: ' . $d->message();
        },

    'astrx.i18n/lang_delete_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Impossibile eliminare la lingua: ' . $d->message();
        },

    'astrx.i18n/lang_mkdir_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Impossibile creare la directory della lingua: ' . $d->message();
        },

    'astrx.i18n/lang_copy_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof LangWriteDiagnostic);
            return 'Impossibile copiare i file di traduzione di origine: ' . $d->message();
        },
];
