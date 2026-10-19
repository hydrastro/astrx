<?php

declare(strict_types = 1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostics — it: the site's request pipeline (a page with no controller,
 * the panic lockdown).
 */
return [
    'astrx.content/controller_missing' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Questa pagina è configurata per usare un controller che non esiste.',

    // Usato anche come id di errore nel corpo JSON di un 503 durante un blocco
    // di emergenza, quindi il testo corrisponde a quella risposta.
    'astrx.panic/locked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Servizio temporaneamente bloccato.',
];
