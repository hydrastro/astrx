<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;
use AstrX\User\Diagnostic\UserDbDiagnostic;
use AstrX\User\Diagnostic\UserDiagnostic;

return [
    'astrx.user/db_error' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UserDbDiagnostic);
            return "Si è verificato un errore del database. Riprova.";
        },

    'astrx.user/operation' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof UserDiagnostic);
            return match ($d->operation()) {
                'login_failed'          => "Nome utente o password errati.",
                'login_restricted'      => "Il tuo tipo di account non è autorizzato ad accedere.",
                'not_verified'          => "Devi verificare la tua email prima di accedere.",
                'registration_closed'   => "Le registrazioni sono attualmente chiuse.",
                'username_taken'        => "Il nome utente è già in uso.",
                'email_taken'           => "L'email di recupero è già in uso.",
                'mailbox_taken'         => "L'indirizzo email è già registrato.",
                'invalid_username'      => $d->detail() !== '' ? $d->detail() : "Formato del nome utente non valido.",
                'invalid_mailbox'       => "Formato dell'indirizzo email di login non valido.",
                'invalid_password'      => $d->detail() !== '' ? $d->detail() : "Formato della password non valido.",
                'passwords_mismatch'    => "Le password non coincidono.",
                'invalid_date'          => "La data di nascita non è valida.",
                'too_young'             => "Non soddisfi il requisito di età minima.",
                'empty_fields'          => "Compila tutti i campi obbligatori.",
                'wrong_password'        => "Password errata.",
                'token_not_found'       => "Il link non è valido o è già stato utilizzato.",
                'token_expired'         => "Il link è scaduto. Richiedine uno nuovo.",
                'token_already_sent'    => "Un link è già stato inviato di recente. Controlla la tua casella di posta.",
                'user_not_found'        => "Nessun account trovato con questo nome utente o email.",
                'avatar_size'           => "Il file caricato è troppo grande.",
                'avatar_extension'      => "Tipo di file non consentito. Carica un PNG, JPEG, GIF o WebP.",
                'avatar_invalid'        => "Il file caricato non è un'immagine valida.",
                'avatar_upload_error'   => "Si è verificato un errore durante il caricamento del file.",
                'avatar_move_failed'    => "Impossibile salvare il file caricato.",
                default                 => "Si è verificato un errore (" . $d->operation() . ").",
            };
        },
];