<?php

declare(strict_types = 1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    // Messaggio generico e sicuro per l'utente. La diagnostica conserva l'errore
    // grezzo del driver per i log lato server; non viene mai mostrato al client.
    'astrx.news/db_error' => fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante il caricamento delle notizie. Riprova più tardi.',
];
