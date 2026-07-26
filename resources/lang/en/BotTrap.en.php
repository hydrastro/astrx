<?php
declare(strict_types=1);

/**
 * Bot-trap (honeypot labyrinth) — en locale.
 *
 * Loaded explicitly via loadDomain(langDir(), 'BotTrap') by BotTrapController,
 * AdminTrapController and — only when the trap is enabled — DefaultTemplateContext
 * (for the hidden footer link). Keys mirror the it counterpart 1:1
 * (check_lang_parity.php).
 */
return [
    // Hidden footer honeypot link — off-screen; humans never see it, greedy
    // HTML-parsing bots follow it. Deliberately innocuous, crawl-tempting text.
    'bottrap.link_text'    => 'Site archive index',

    // The maze page a trapped bot receives: a heading, a plausible intro line
    // and the fake "next" label (rendered inside the normal site shell).
    'bottrap.maze.heading' => 'Archive index',
    'bottrap.maze.intro'   => 'This archive is being reorganised. The entries below lead to further sections.',
    'bottrap.maze.link'    => 'Continue to page',

    // Admin log viewer.
    'bottrap.admin.heading' => 'Bot trap',
    'bottrap.admin.intro'   => 'Requests that ignored robots.txt and followed the hidden honeypot link. Identities are hashed (sha256) — no raw IP is ever stored.',

    // Settings form.
    'bottrap.admin.settings_heading' => 'Settings',
    'bottrap.admin.settings_hint'    => 'Toggle the honeypot and tune its limits. The tarpit delay is capped at 10 seconds and links per maze page at 20, so a bad edit can never hang the server or emit an unbounded page.',
    'bottrap.admin.save'             => 'Save settings',

    'bottrap.admin.enabled' => 'Trap enabled',
    'bottrap.admin.tarpit'  => 'Tarpit delay (seconds)',
    'bottrap.admin.links'   => 'Links per maze page',
    'bottrap.admin.logging' => 'Logging hits',
    'bottrap.admin.yes'     => 'Yes',
    'bottrap.admin.no'      => 'No',
    'bottrap.admin.time'    => 'Time',
    'bottrap.admin.ident'   => 'Hashed identity',
    'bottrap.admin.path'    => 'Path',
    'bottrap.admin.ua'      => 'User agent',
    'bottrap.admin.referer' => 'Referer',
    'bottrap.admin.count'   => 'Recent hits shown',
    'bottrap.admin.none'    => 'No trap hits recorded yet.',
];
