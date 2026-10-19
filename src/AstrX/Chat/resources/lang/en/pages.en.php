<?php
declare(strict_types=1);

/**
 * pages — en: the Chat module's keys (merged into the site's pages catalog).
 */
return [
    'WORDING_CHAT'              => 'chat',
    'WORDING_CHAT_STREAM'       => 'chat-stream',
    'WORDING_CHAT_LOGIN'        => 'chat-login',
    'WORDING_CHAT_WAIT'         => 'chat-wait',
    'WORDING_CHAT_USERS'        => 'chat-users',
    'WORDING_CHAT_PM'           => 'chat-pm',
    'WORDING_CHAT_SETTINGS'     => 'chat-settings',
    'WORDING_CHAT_HELP'         => 'chat-help',
    'WORDING_CHAT_ADMIN'        => 'chat-admin',
    'WORDING_CHAT_FILE'         => 'chat-file',

    // Bot-trap honeypot. WORDING_TRAP is a template=0 raw-controller page (its
    // title/description are never rendered — the controller emits its own maze
    // and exit()s), reached only by the hidden footer link + misbehaving bots.
    'WORDING_ADMIN_CONFIG_CHAT' => 'admin-config-chat',
    'WORDING_ADMIN_CHAT_FILTERS' => 'admin-chat-filters',
    'WORDING_ADMIN_CHAT_FILTERS.title'       => 'Chat filters',
    'WORDING_ADMIN_CHAT_FILTERS.description' => 'Manage the chat word and link filters.',

    // Chat page titles / descriptions
    'WORDING_CHAT.title'                    => 'Chat',
    'WORDING_CHAT.description'              => 'Live community chat room.',
    'WORDING_CHAT_LOGIN.title'              => 'Chat',
    'WORDING_CHAT_LOGIN.description'        => 'Enter the chat.',
    'WORDING_CHAT_SETTINGS.title'           => 'Chat settings',
    'WORDING_CHAT_SETTINGS.description'     => 'Your chat display preferences.',
    'WORDING_CHAT_HELP.title'               => 'Chat help',
    'WORDING_CHAT_HELP.description'         => 'Formatting, commands and rules.',
    'WORDING_CHAT_ADMIN.title'              => 'Chat administration',
    'WORDING_CHAT_ADMIN.description'        => 'Moderator tools for the chat room.',
    'WORDING_CHAT_WAIT.title'               => 'Waiting room',
    'WORDING_CHAT_WAIT.description'         => 'Please wait to enter the chat.',
    'WORDING_ADMIN_CONFIG_CHAT.title'       => 'Config — Chat',
    'WORDING_ADMIN_CONFIG_CHAT.description' => 'Edit chat configuration.',
];
