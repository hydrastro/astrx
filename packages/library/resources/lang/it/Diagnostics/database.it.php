<?php

declare(strict_types = 1);

use AstrX\Database\ConnectionFailure;
use AstrX\Database\Diagnostic\DatabaseConfigIncompleteDiagnostic;
use AstrX\Database\Diagnostic\DatabaseUnavailableDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostiche di connessione al database — locale it.
 *
 * Sono i messaggi che un operatore legge quando il sito non riesce a
 * raggiungere il database. Tutto ciò che compare qui è SICURO DA MOSTRARE:
 * viene costruito dal nome del driver, dallo SQLSTATE, dal numero di errore del
 * driver e da una classificazione. DSN, host, porta, utente e password non sono
 * disponibili a queste closure — DatabaseUnavailableDiagnostic non li conserva —
 * quindi nessuna modifica a questo file può pubblicare una credenziale.
 * Mantenerlo così.
 */
return [
    'astrx.database/connect_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof DatabaseUnavailableDiagnostic);

            $reason = match ($d->reason()) {
                ConnectionFailure::UNREACHABLE      => 'nessuna risposta all\'indirizzo configurato',
                ConnectionFailure::AUTH_REJECTED    => 'il server ha rifiutato le credenziali configurate',
                ConnectionFailure::UNKNOWN_DATABASE => 'il database configurato non esiste',
                ConnectionFailure::DRIVER_MISSING   => 'questa build di PHP non ha quel driver PDO',
                default                             => 'il driver ha segnalato un errore di connessione',
            };

            $codes = [];
            if ($d->sqlState() !== '')  { $codes[] = 'SQLSTATE ' . $d->sqlState(); }
            if ($d->driverCode() !== 0) { $codes[] = 'errore driver ' . $d->driverCode(); }
            $detail = $codes === [] ? '' : ' (' . implode(', ', $codes) . ')';

            return 'Database non disponibile: ' . $reason . $detail
                . '. Driver "' . $d->driver() . '"; le impostazioni sono in resources/config/PDO.config.php. '
                . 'I dettagli di connessione sono volutamente omessi da questo messaggio.';
        },

    'astrx.database/config_incomplete' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof DatabaseConfigIncompleteDiagnostic);

            $keys = $d->missingKeys();

            return 'Configurazione del database incompleta: resources/config/PDO.config.php '
                . 'non dichiara ' . ($keys === [] ? 'le credenziali' : '"' . implode('", "', $keys) . '"')
                . '. Nessuna connessione è stata tentata — AstrX non indovina mai una credenziale. '
                . 'Copiare PDO.config.php.example e completarlo.';
        },
];
