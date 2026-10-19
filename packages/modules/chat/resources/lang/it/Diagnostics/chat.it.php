<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.chat/db_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Si è verificato un errore del database nella chat. Riprova.',

    'astrx.chat/gate_denied' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Non sei autorizzato a farlo.',

    'astrx.chat/empty' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il tuo messaggio non può essere vuoto.',

    'astrx.chat/too_long' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il tuo messaggio è troppo lungo.',

    'astrx.chat/flood' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Stai pubblicando troppo velocemente. Aspetta un momento.',

    'astrx.chat/muted' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Sei silenziato e non puoi pubblicare in questo momento.',

    'astrx.chat/room_forbidden' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Non hai accesso a quella stanza.',

    'astrx.chat/room_not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Quella stanza non esiste.',

    'astrx.chat/nick_invalid' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Scegli un nickname valido (2–32 lettere, cifre, spazi).',

    'astrx.chat/entry_password' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Password d\'ingresso errata.',

    'astrx.chat/not_found' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Messaggio non trovato.',

    'astrx.chat/kicked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Sei stato espulso dalla chat.',

    'astrx.chat/banned' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Sei stato bandito dalla chat.',

    'astrx.chat/nick_taken' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Quel nickname è già in uso. Scegline un altro.',

    'astrx.chat/censored' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il tuo messaggio è stato bloccato dal filtro parole.',

    'astrx.chat/filter_blocked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il tuo messaggio è stato bloccato da un filtro della chat.',

    'astrx.chat/filter_kicked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Sei stato rimosso dalla chat da un filtro.',

    'astrx.chat/nick_blocked' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Quel nickname non è consentito. Scegline un altro.',

    'astrx.chat/upload_disabled' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Gli allegati immagine non sono abilitati.',

    'astrx.chat/upload_error' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Il caricamento del file non è riuscito. Riprova.',

    'astrx.chat/upload_too_big' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Quell\'immagine è troppo grande.',

    'astrx.chat/upload_type' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Quel tipo di file non è consentito.',

    'astrx.chat/upload_invalid' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Quel file non è un\'immagine valida.',

    'astrx.chat/upload_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Impossibile salvare l\'immagine.',

    'astrx.chat/pm_target' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Quell\'utente non è attualmente nella chat.',

    'astrx.chat/not_in_chat' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Devi prima entrare nella chat.',

    'astrx.chat/full' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'La chat è piena. Riprova più tardi.',

    // Rete di sicurezza per il ramo default di ChatService::opErr(). Non
    // raggiungibile in condizioni normali (ogni chiamata usa un op noto), ma
    // mantenuto così un op imprevisto mostra un messaggio pulito anziché un id.
    'astrx.chat/unknown' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Qualcosa è andato storto nella chat. Riprova.',
];
