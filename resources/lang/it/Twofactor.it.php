<?php
declare(strict_types=1);

/**
 * Gestione due fattori — pagina utente — locale it.
 * Le chiavi rispecchiano 1:1 la controparte en (check_lang_parity.php).
 */
return [
    'twofactor.heading'          => 'Autenticazione a due fattori',
    'twofactor.intro'            => 'Aggiungi un codice monouso a tempo (TOTP) da un app di autenticazione come secondo fattore all accesso.',
    'twofactor.status_on'        => 'L autenticazione a due fattori è ATTIVA.',
    'twofactor.status_off'       => 'L autenticazione a due fattori è disattivata.',
    'twofactor.code'             => 'Codice',
    'twofactor.begin'            => 'Configura i due fattori',
    'twofactor.confirm'          => 'Conferma e attiva',
    'twofactor.cancel'           => 'Annulla configurazione',
    'twofactor.disable'          => 'Disattiva i due fattori',
    'twofactor.setup_intro'      => 'Aggiungi questo segreto alla tua app di autenticazione (Aegis, FreeOTP, Google Authenticator, …), poi inserisci un codice attuale qui sotto per confermare.',
    'twofactor.secret_label'     => 'Chiave segreta',
    'twofactor.uri_label'        => 'Oppure importa questo URI otpauth:',
    'twofactor.confirm_label'    => 'Inserisci un codice per confermare',
    'twofactor.disable_label'    => 'Inserisci un codice attuale o di recupero per disattivarla',
    'twofactor.recovery_heading' => 'I tuoi codici di recupero',
    'twofactor.recovery_intro'   => 'Salvali subito in un posto sicuro — sono mostrati una sola volta. Ogni codice funziona una volta sola se perdi l autenticatore.',
    'twofactor.enabled'          => 'Autenticazione a due fattori attivata.',
    'twofactor.disabled'         => 'Autenticazione a due fattori disattivata.',
    'twofactor.bad_code'         => 'Codice non valido. Riprova.',
];
