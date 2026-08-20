<?php

declare(strict_types = 1);

use AstrX\I18n\Translator;
use AstrX\Injector\Diagnostic\CircularDependencyDiagnostic;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostiche dei sottosistemi dei moduli opzionali (contenuti, media, ricerca,
 * inviti, trappola per bot, blocco di emergenza) più la guardia sui cicli
 * dell'injector — locale it. Ogni id qui presente veniva prima reso come timbro
 * grezzo "[FALLBACK:…]" in entrambe le lingue.
 *
 * Gli errori di database mostrano di proposito una frase generica: la
 * diagnostica conserva il messaggio del driver per i log lato server, ma quel
 * messaggio nomina tabelle, colonne e talvolta valori, e questo testo può
 * arrivare a una pagina pubblica. Stessa politica di astrx.news/db_error.
 */
return [
    'astrx.injector/circular_dependency' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof CircularDependencyDiagnostic);
            return "Dipendenza circolare durante la costruzione di \"{$d->getClassName()}\" — "
                . 'due classi si richiedono a vicenda nei costruttori.';
        },

    'astrx.content/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante il caricamento della pagina. Riprova più tardi.',

    'astrx.content/controller_missing' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Questa pagina è configurata per usare un controller che non esiste.',

    'astrx.media/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante il caricamento della libreria multimediale. Riprova più tardi.',

    'astrx.media/upload_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Non è stato possibile salvare il file caricato. Riprova.',

    'astrx.search/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante la ricerca. Riprova più tardi.',

    'astrx.search/index_rebuild_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Non è stato possibile ricostruire l'indice di ricerca. L'indice precedente è ancora attivo.",

    'astrx.search/index_request_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Non è stato possibile accodare la richiesta di indicizzazione.",

    'astrx.search/index_reset_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Non è stato possibile azzerare l'indice di ricerca.",

    'astrx.invite/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante la gestione degli inviti. Riprova più tardi.',

    'astrx.bottrap/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database durante la registrazione della trappola per bot.',

    // Usato anche come id di errore nel corpo JSON di un 503 durante un blocco
    // di emergenza, quindi il testo corrisponde a quella risposta.
    'astrx.panic/locked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Servizio temporaneamente bloccato.',
];
