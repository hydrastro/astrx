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
    'user.field.mailbox_hint'    => 'Solo la parte locale (es. alice — senza @dominio)',
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
    'user.recover.reset_heading'     => 'Imposta una nuova password',
    'user.recover.reset_description' => 'Scegli una nuova password per il tuo account. Dopo il salvataggio, accedi con la nuova password.',
    'user.recover.reset_new'         => 'Nuova password',
    'user.recover.reset_repeat'      => 'Ripeti la nuova password',
    'user.recover.reset_submit'      => 'Imposta nuova password',
    'user.recover.reset_done'        => 'La tua password è stata aggiornata. Accedi con la nuova password.',
    'user.recover.reset_expired'     => 'Il link di recupero è scaduto. Richiedine uno nuovo.',

    // -------------------------------------------------------------------------
    // Home utente
    // -------------------------------------------------------------------------
    'user.home.heading'          => 'Benvenuto',
    'user.home.body'             => 'Hai effettuato l\'accesso.',
    'user.home.profile_heading'  => 'Profilo',
    'user.home.profile_text'     => 'Visualizza il tuo profilo pubblico.',
    'user.home.settings_heading' => 'Impostazioni',
    'user.home.settings_text'    => 'Gestisci il tuo account.',
    'user.home.logout'           => 'Esci',
    'user.home.profile_heading.desc'  => 'Visualizza e modifica il tuo profilo pubblico.',
    'user.home.settings_heading.desc' => "Cambia la password, l'avatar e le preferenze.",
    'user.home.logout.desc'           => 'Termina la sessione corrente.',

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

    // ---- Webmail -------------------------------------------------------
    'webmail.heading'             => 'Webmail',
    'webmail.heading.desc'        => 'Leggi e invia email dalla tua casella di posta.',
    'webmail.login.heading'       => 'Connettiti alla casella di posta',
    'webmail.login.body'          => 'Inserisci la password della casella di posta per accedere alle tue email.',
    'webmail.login.password'      => 'Password della casella di posta',
    'webmail.login.btn'           => 'Connetti',
    'webmail.login.error'         => 'Impossibile connettersi. Controlla la password e riprova.',
    'webmail.compose.heading'     => 'Scrivi',
    'webmail.compose.from'        => 'Da',
    'webmail.compose.to'          => 'A',
    'webmail.compose.subject'     => 'Oggetto',
    'webmail.compose.body'        => 'Messaggio',
    'webmail.compose.send'        => 'Invia',
    'webmail.compose.save_draft'  => 'Salva bozza',
    'webmail.compose.cc'          => 'CC',
    'webmail.compose.bcc'         => 'CCN',
    'webmail.compose.cancel'      => 'Annulla',
    'webmail.folders'             => 'Cartelle',
    'webmail.inbox'               => 'Posta in arrivo',
    'webmail.from'                => 'Da',
    'webmail.to'                  => 'A',
    'webmail.subject'             => 'Oggetto',
    'webmail.date'                => 'Data',
    'webmail.raw_headers_failed'  => '(impossibile recuperare le intestazioni)',
    'webmail.actions'             => 'Azioni',
    'webmail.no_messages'         => 'Nessun messaggio in questa cartella.',
    'webmail.btn_compose'         => 'Scrivi',
    'webmail.btn_reply'           => 'Rispondi',
    'webmail.btn_delete'          => 'Elimina',
    'webmail.btn_move'            => 'Sposta',
    'webmail.btn_mark_read'       => 'Segna come letto',
    'webmail.btn_mark_unread'     => 'Segna come non letto',
    'webmail.btn_mark_read_bulk'  => 'Letti',
    'webmail.btn_mark_unread_bulk'=> 'Non letti',
    'webmail.btn_delete_bulk'     => 'Elimina',
    'webmail.btn_move_bulk'       => 'Sposta',
    'webmail.btn_forward'         => 'Inoltra',
    'webmail.compose.priority'    => 'Priorità',
    'webmail.compose.priority_high'  => 'Alta',
    'webmail.compose.priority_normal'=> 'Normale',
    'webmail.compose.priority_low'   => 'Bassa',
    'webmail.compose.read_receipt'=> 'Richiedi conferma di lettura',
    'webmail.compose.reply_to'    => 'Rispondi a',
    'webmail.forward_separator'   => '-------- Messaggio inoltrato --------',
    'webmail.view_headers'        => 'Visualizza intestazioni',
    'webmail.raw_headers_heading' => 'Intestazioni grezze del messaggio',
    'webmail.select_all'          => 'Seleziona/deseleziona tutto',
    'webmail.go'                  => 'Vai',
    'webmail.show'                => 'Mostra',
    'webmail.sort'                => 'Ordina',
    'webmail.sort_newest'         => 'Prima i più recenti',
    'webmail.sort_oldest'         => 'Prima i più vecchi',
    'webmail.viewing'             => 'Visualizzazione messaggi',
    'webmail.per_page'            => 'per pagina',
    'webmail.transform'           => 'Trasforma selezionati',
    'webmail.move_selected'       => 'Sposta selezionati in',
    'webmail.messages_deleted'    => 'Messaggi selezionati eliminati.',
    'webmail.messages_moved'      => 'Messaggi selezionati spostati.',
    'webmail.btn_back'            => '← Indietro',
    'webmail.btn_disconnect'      => 'Disconnetti',
    'webmail.move_to'             => 'Sposta in',
    'webmail.prev'                => '← Prec',
    'webmail.next'                => 'Succ →',
    'webmail.page'                => 'Pagina',
    'webmail.of'                  => 'di',
    'webmail.total'               => 'Totale',
    'webmail.message_deleted'     => 'Messaggio eliminato.',
    'webmail.message_moved'       => 'Messaggio spostato.',
    'webmail.message_sent'        => 'Messaggio inviato.',
    'webmail.draft_saved'         => 'Bozza salvata.',
    'webmail.sender_trusted'      => 'Mittente aggiunto alla lista dei fidati.',
    'webmail.sender_untrusted'    => 'Mittente rimosso dalla lista dei fidati.',
    'webmail.images_blocked'      => 'Le immagini esterne sono state bloccate.',
    'webmail.trust_sender'        => 'Considera fidato questo mittente (mostra immagini)',
    'webmail.untrust_sender'      => 'Rimuovi fiducia',
    'webmail.sender_trusted_notice' => 'Questo mittente è fidato — le immagini sono mostrate.',
    'webmail.download'            => 'Scarica',
    'webmail.attachments'         => 'Allegati',
    'webmail.compose_fields_required' => 'A, Oggetto e corpo del messaggio sono obbligatori.',


    // ---- Selettore tema (fix95) ---------------------------------------------
    'user.settings.theme'                  => 'Tema',
    'user.settings.theme_desc'             => 'Scegli come appare il sito per te. "Predefinito" significa usa il tema globale del sito.',
    'user.settings.theme_default'          => 'Predefinito (usa il tema del sito)',
    'user.settings.theme_saved'            => 'Preferenza tema salvata.',
    'user.settings.theme_invalid'          => 'Questo tema non è installato.',

    // ---- Chiavi API (fix100) ------------------------------------------------
    'user.settings.api_keys'                => 'Chiavi API',
    'user.settings.api_keys_desc'           => 'Usa le chiavi API per accedere al sito in modo programmatico. Ogni chiave equivale al tuo account &mdash; tienila segreta. Una chiave mostrata UNA volta alla creazione non può essere recuperata, solo revocata.',
    'user.settings.api_keys_label'          => 'Etichetta',
    'user.settings.api_keys_created'        => 'Creata',
    'user.settings.api_keys_last_used'      => 'Ultimo uso',
    'user.settings.api_keys_expires'        => 'Scadenza',
    'user.settings.api_keys_status'         => 'Stato',
    'user.settings.api_keys_actions'        => 'Azioni',
    'user.settings.api_keys_active'         => 'Attiva',
    'user.settings.api_keys_revoked'        => 'Revocata',
    'user.settings.api_keys_revoke'         => 'Revoca',
    'user.settings.api_keys_create'         => 'Crea nuova chiave',
    'user.settings.api_keys_new_label_ph'   => 'es. "Mio script CLI"',
    'user.settings.api_keys_none'           => 'Non hai ancora chiavi API.',
    'user.settings.api_keys_save_now'       => 'Salva questa chiave ora',
    'user.settings.api_keys_save_warn'      => "È l'unica volta che verrà mostrata. Se la perdi, revoca questa voce e creane una nuova.",
    'user.settings.api_keys_no_permission'  => "Non hai il permesso di creare chiavi API. Chiedi a un amministratore se ti serve l'accesso API.",
];
