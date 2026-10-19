<?php

declare(strict_types = 1);

use AstrX\Database\ConnectionFailure;
use AstrX\Database\Diagnostic\DatabaseConfigIncompleteDiagnostic;
use AstrX\Database\Diagnostic\DatabaseUnavailableDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Connection-level database diagnostics — en locale.
 *
 * These are the messages an operator reads when the site cannot reach its
 * database at all. Everything here is SAFE TO SHOW: it is built from the
 * driver name, the SQLSTATE, the driver's error number and a classification.
 * The DSN, host, port, username and password are never available to these
 * closures — DatabaseUnavailableDiagnostic does not carry them — so no edit
 * to this file can publish a credential. Keep it that way.
 */
return [
    'astrx.database/connect_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof DatabaseUnavailableDiagnostic);

            $reason = match ($d->reason()) {
                ConnectionFailure::UNREACHABLE      => 'nothing answered at the configured address',
                ConnectionFailure::AUTH_REJECTED    => 'the server rejected the configured credentials',
                ConnectionFailure::UNKNOWN_DATABASE => 'the configured database does not exist',
                ConnectionFailure::DRIVER_MISSING   => 'this PHP build has no such PDO driver',
                default                             => 'the driver reported a connection error',
            };

            $codes = [];
            if ($d->sqlState() !== '')  { $codes[] = 'SQLSTATE ' . $d->sqlState(); }
            if ($d->driverCode() !== 0) { $codes[] = 'driver error ' . $d->driverCode(); }
            $detail = $codes === [] ? '' : ' (' . implode(', ', $codes) . ')';

            return 'The database is unavailable: ' . $reason . $detail
                . '. Driver "' . $d->driver() . '"; settings are in resources/config/PDO.config.php. '
                . 'Connection details are withheld from this message on purpose.';
        },

    'astrx.database/config_incomplete' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof DatabaseConfigIncompleteDiagnostic);

            $keys = $d->missingKeys();

            return 'The database configuration is incomplete: resources/config/PDO.config.php '
                . 'declares no ' . ($keys === [] ? 'credentials' : '"' . implode('", "', $keys) . '"')
                . '. No connection was attempted — AstrX never guesses a credential. '
                . 'Copy PDO.config.php.example and fill it in.';
        },
];
