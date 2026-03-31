<?php
declare(strict_types=1);

/**
 * Traduzioni per la sezione utente — localizzazione italiana.
 *
 * Le chiavi WORDING_* (slug URL) sono in pages.it.php, caricato globalmente.
 * Le chiavi .title / .description evitano i notice MissingTranslation.
 */
return [
    // -------------------------------------------------------------------------
    // Meta pagina — titolo e descrizione
    // -------------------------------------------------------------------------
    'WORDING_LOGIN.title'          => 'Accedi',
    'WORDING_LOGIN.description'    => 'Accedi al tuo account.',

    'WORDING_REGISTER.title'       => 'Registrati',
    'WORDING_REGISTER.description' => 'Crea un nuovo account.',

    'WORDING_RECOVER.title'        => 'Recupero account',
    'WORDING_RECOVER.description'  => 'Reimposta la tua password.',

    'WORDING_SETTINGS.title'       => 'Impostazioni',
    'WORDING_SETTINGS.description' => 'Gestisci le impostazioni del tuo account.',

    'WORDING_USER_HOME.title'      => 'Home',
    'WORDING_USER_HOME.description'=> 'La tua pagina personale.',

    'WORDING_LOGOUT.title'         => 'Esci',
    'WORDING_LOGOUT.description'   => 'Disconnettiti dal tuo account.',

    'WORDING_USER.title'           => 'Area utente',
    'WORDING_USER.description'     => 'Accedi o crea il tuo account.',

    // -------------------------------------------------------------------------
    // Etichette campi comuni
    // -------------------------------------------------------------------------
    'user.field.username'        => 'Nome utente',
    'user.field.password'        => 'Password',
    'user.field.old_password'    => 'Password attuale',
    'user.field.repeat'          => 'Ripeti password',
    'user.field.mailbox'         => 'Email di accesso',
    'user.field.email'           => 'Email di recupero',
    'user.field.display_name'    => 'Nome visualizzato',
    'user.field.birth_date'      => 'Data di nascita',

    // -------------------------------------------------------------------------
    // Captcha
    // -------------------------------------------------------------------------
    'user.captcha.label'         => 'Inserisci il testo del captcha',

    // -------------------------------------------------------------------------
    // Login
    // -------------------------------------------------------------------------
    'user.login.heading'         => 'Area utente',
    'user.login.submit'          => 'Accedi',
    'user.login.remember_me'     => 'Ricordami',
    'user.login.lost_password'   => 'Password dimenticata?',
    'user.login.need_account'    => 'Non hai un account?',
    'user.login.register'        => 'Registrati',

    // -------------------------------------------------------------------------
    // Registrazione
    // -------------------------------------------------------------------------
    'user.register.heading'      => 'Registrazione',
    'user.register.description'  => 'Crea un nuovo account.',
    'user.register.submit'       => 'Registrati',
    'user.register.back_to_login'=> 'Torna al login',
    'user.register.closed'       => 'Le registrazioni sono attualmente chiuse.',

    // -------------------------------------------------------------------------
    // Recupero
    // -------------------------------------------------------------------------
    'user.recover.heading'       => 'Recupero account',
    'user.recover.description'   => 'Inserisci il tuo nome utente o email di recupero e ti invieremo un link.',
    'user.recover.identifier'    => 'Nome utente o email',
    'user.recover.submit'        => 'Invia link',
    'user.recover.back_to_login' => 'Torna al login',
    'user.recover.unavailable'   => 'Il recupero della password non è disponibile.',

    // -------------------------------------------------------------------------
    // Home utente
    // -------------------------------------------------------------------------
    'user.home.heading'          => 'Benvenuto',
    'user.home.body'             => 'Hai effettuato l\'accesso.',
    'user.home.profile_heading'  => 'Profilo',
    'user.home.profile_text'     => 'Visualizza il tuo profilo pubblico.',
    'user.home.settings_heading' => 'Impostazioni',
    'user.home.settings_text'    => 'Gestisci il tuo account.',

    // -------------------------------------------------------------------------
    // Impostazioni
    // -------------------------------------------------------------------------
    'user.settings.heading'           => 'Impostazioni account',
    'user.settings.current_value'     => 'Attuale',
    'user.settings.submit'            => 'Salva',
    'user.settings.avatar'            => 'Avatar',
    'user.settings.set_avatar'        => 'Carica avatar',
    'user.settings.remove_avatar'     => 'Rimuovi avatar',
    'user.settings.max_size'          => 'Dimensione massima',
    'user.settings.display_name'      => 'Nome visualizzato',
    'user.settings.new_display_name'  => 'Nuovo nome visualizzato',
    'user.settings.recovery_email'    => 'Email di recupero',
    'user.settings.new_email'         => 'Nuova email di recupero',
    'user.settings.username'          => 'Nome utente',
    'user.settings.new_username'      => 'Nuovo nome utente',
    'user.settings.password'          => 'Cambia password',
    'user.settings.verify_email'      => 'Verifica email',
    'user.settings.verify_desc'       => 'La tua email di recupero non è ancora verificata. Invia un link di verifica.',
    'user.settings.delete'            => 'Elimina account',
    'user.settings.delete_confirm'    => 'Questa azione è irreversibile. Inserisci la tua password per confermare.',


    // -------------------------------------------------------------------------
    // Messaggi di successo / info
    // -------------------------------------------------------------------------
    'user.register.terms_label'        => 'Accetto i Termini e Condizioni',
    'user.register.data_usage_label'   => 'Accetto l\'Informativa sul Trattamento dei Dati',
    'user.register.terms_required'     => 'Devi accettare i Termini e Condizioni per registrarti.',
    'user.register.data_usage_required'=> 'Devi accettare l\'Informativa sul Trattamento dei Dati per registrarti.',
    'user.register.success' => 'Registrazione completata! Ora puoi accedere.',
    'user.recover.sent'     => 'Se esiste un account con quel nome utente o email, ti abbiamo inviato un link.',

    // -------------------------------------------------------------------------
    // Pagina profilo
    // -------------------------------------------------------------------------
    'user.profile.heading'      => 'Profilo',
    'user.profile.not_found'    => 'Utente non trovato.',
    'user.profile.joined'       => 'Membro dal',
    'user.profile.group'        => 'Ruolo',
    'user.profile.verified'     => 'Verificato',
    'user.profile.settings_link'=> 'Modifica le tue impostazioni',

    // -------------------------------------------------------------------------
    // Etichette gruppo
    // -------------------------------------------------------------------------
    'user.group.admin'  => 'Amministratore',
    'user.group.mod'    => 'Moderatore',
    'user.group.user'   => 'Membro',
    'user.group.guest'  => 'Ospite',

    // -------------------------------------------------------------------------
    // Meta pagina
    // -------------------------------------------------------------------------
    'WORDING_PROFILE.title'        => 'Profilo',
    'WORDING_PROFILE.description'  => 'Visualizza un profilo utente.',
];
