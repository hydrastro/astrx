<?php
declare(strict_types=1);

/**
 * pages — it: the Chat module's keys (merged into the site's pages catalog).
 */
return [
    'WORDING_CHAT'              => 'chat',
    'WORDING_CHAT_STREAM'       => 'chat-flusso',
    'WORDING_CHAT_LOGIN'        => 'chat-accesso',
    'WORDING_CHAT_WAIT'         => 'chat-attesa',
    'WORDING_CHAT_USERS'        => 'chat-utenti',
    'WORDING_CHAT_PM'           => 'chat-mp',
    'WORDING_CHAT_SETTINGS'     => 'chat-impostazioni',
    'WORDING_CHAT_HELP'         => 'chat-aiuto',
    'WORDING_CHAT_ADMIN'        => 'chat-admin',
    'WORDING_CHAT_FILE'         => 'chat-file',

    // Honeypot per bot. WORDING_TRAP è una pagina con controller raw (template=0):
    // titolo/descrizione non vengono mai mostrati — il controller genera il suo
    // labirinto e fa exit(), raggiunta solo dal link nascosto e dai bot scorretti.
    'WORDING_ADMIN_CONFIG_CHAT' => 'admin-config-chat',
    'WORDING_ADMIN_CHAT_FILTERS' => 'admin-chat-filtri',
    'WORDING_ADMIN_CHAT_FILTERS.title'       => 'Filtri chat',
    'WORDING_ADMIN_CHAT_FILTERS.description' => 'Gestione dei filtri di parole e link della chat.',

    // Titoli / descrizioni delle pagine chat
    'WORDING_CHAT.title'                    => 'Chat',
    'WORDING_CHAT.description'              => 'Chat della community in tempo reale.',
    'WORDING_CHAT_LOGIN.title'              => 'Chat',
    'WORDING_CHAT_LOGIN.description'        => 'Entra nella chat.',
    'WORDING_CHAT_SETTINGS.title'           => 'Impostazioni chat',
    'WORDING_CHAT_SETTINGS.description'     => 'Le tue preferenze di visualizzazione della chat.',
    'WORDING_CHAT_HELP.title'               => 'Guida alla chat',
    'WORDING_CHAT_HELP.description'         => 'Formattazione, comandi e regole.',
    'WORDING_CHAT_ADMIN.title'              => 'Amministrazione chat',
    'WORDING_CHAT_ADMIN.description'        => 'Strumenti di moderazione della chat.',
    'WORDING_CHAT_WAIT.title'               => 'Sala d\'attesa',
    'WORDING_CHAT_WAIT.description'         => 'Attendi per entrare nella chat.',
    'WORDING_ADMIN_CONFIG_CHAT.title'       => 'Configurazione — Chat',
    'WORDING_ADMIN_CONFIG_CHAT.description' => 'Modifica la configurazione della chat.',
];
