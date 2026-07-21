<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;
use AstrX\User\Diagnostic\UserDbDiagnostic;
use AstrX\User\Diagnostic\UserInvalidUsernameDiagnostic;
use AstrX\User\Diagnostic\UserInvalidPasswordDiagnostic;
use AstrX\User\Diagnostic\InvalidThemeDiagnostic;

return [
    'astrx.user/db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UserDbDiagnostic);
            return "Si è verificato un errore del database. Riprova.";
        },

    'astrx.user/login_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Nome utente o password errati.",

    'astrx.user/login_restricted' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Il tuo tipo di account non è autorizzato ad accedere.",

    'astrx.user/not_verified' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Devi verificare la tua email prima di accedere.",

    'astrx.user/registration_closed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Le registrazioni sono attualmente chiuse.",

    'astrx.user/username_taken' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Il nome utente è già in uso.",

    'astrx.user/email_taken' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "L'email di recupero è già in uso.",

    'astrx.user/mailbox_taken' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "L'indirizzo email è già registrato.",

    'astrx.user/invalid_username' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UserInvalidUsernameDiagnostic);
            return $d->detail() !== '' ? $d->detail() : "Formato del nome utente non valido.";
        },

    'astrx.user/invalid_password' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UserInvalidPasswordDiagnostic);
            return $d->detail() !== '' ? $d->detail() : "Formato della password non valido.";
        },

    'astrx.user/invalid_mailbox' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Formato dell'indirizzo email di login non valido.",

    'astrx.user/passwords_mismatch' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Le password non coincidono.",

    'astrx.user/invalid_date' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "La data di nascita non è valida.",

    'astrx.user/too_young' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Non soddisfi il requisito di età minima.",

    'astrx.user/empty_fields' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Compila tutti i campi obbligatori.",

    'astrx.user/wrong_password' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Password errata.",

    'astrx.user/token_not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Il link non è valido o è già stato utilizzato.",

    'astrx.user/token_expired' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Il link è scaduto. Richiedine uno nuovo.",

    'astrx.user/token_already_sent' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Un link è già stato inviato di recente. Controlla la tua casella di posta.",

    'astrx.user/not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Nessun account trovato con questo nome utente o email.",

    'astrx.user/avatar_size' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Il file caricato è troppo grande.",

    'astrx.user/avatar_extension' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Tipo di file non consentito. Carica un PNG, JPEG, GIF o WebP.",

    'astrx.user/avatar_invalid' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Il file caricato non è un'immagine valida.",

    'astrx.user/avatar_upload_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Si è verificato un errore durante il caricamento del file.",

    'astrx.user/avatar_move_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        "Impossibile salvare il file caricato.",

    'astrx.user/invalid_theme' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof InvalidThemeDiagnostic);
            return 'Quel tema non è installato. Sceglierne un altro.';
        },
];
