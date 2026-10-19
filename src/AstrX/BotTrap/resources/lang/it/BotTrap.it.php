<?php
declare(strict_types=1);

/**
 * Bot-trap (labirinto honeypot) — locale it.
 *
 * Caricato esplicitamente con loadDomain(langDir(), 'BotTrap') da
 * BotTrapController, AdminTrapController e — solo quando la trappola è attiva —
 * DefaultTemplateContext (per il link nascosto nel footer). Le chiavi
 * corrispondono 1:1 alla controparte en (check_lang_parity.php).
 */
return [
    // Link honeypot nascosto nel footer — fuori schermo; gli umani non lo vedono,
    // i bot che analizzano l'HTML lo seguono. Testo volutamente allettante.
    'bottrap.link_text'    => 'Indice archivio del sito',

    // La pagina-labirinto servita al bot intrappolato: titolo, riga introduttiva
    // plausibile ed etichetta finta (resa dentro il normale guscio del sito).
    'bottrap.maze.heading' => 'Indice archivio',
    'bottrap.maze.intro'   => 'Questo archivio è in fase di riorganizzazione. Le voci sottostanti conducono ad altre sezioni.',
    'bottrap.maze.link'    => 'Continua alla pagina',

    // Visualizzatore log per l'amministratore.
    'bottrap.admin.heading' => 'Trappola per bot',
    'bottrap.admin.intro'   => 'Richieste che hanno ignorato robots.txt e seguito il link honeypot nascosto. Le identità sono hashate (sha256) — nessun IP grezzo viene mai memorizzato.',

    // Modulo impostazioni.
    'bottrap.admin.settings_heading' => 'Impostazioni',
    'bottrap.admin.settings_hint'    => 'Attiva o disattiva l\'honeypot e regola i suoi limiti. Il ritardo tarpit è limitato a 10 secondi e i link per pagina del labirinto a 20, così una modifica errata non può mai bloccare il server o generare una pagina illimitata.',
    'bottrap.admin.save'             => 'Salva impostazioni',

    'bottrap.admin.enabled' => 'Trappola attiva',
    'bottrap.admin.tarpit'  => 'Ritardo tarpit (secondi)',
    'bottrap.admin.links'   => 'Link per pagina del labirinto',
    'bottrap.admin.logging' => 'Registrazione accessi',
    'bottrap.admin.yes'     => 'Sì',
    'bottrap.admin.no'      => 'No',
    'bottrap.admin.time'    => 'Ora',
    'bottrap.admin.ident'   => 'Identità hashata',
    'bottrap.admin.path'    => 'Percorso',
    'bottrap.admin.ua'      => 'User agent',
    'bottrap.admin.referer' => 'Referer',
    'bottrap.admin.count'   => 'Accessi recenti mostrati',
    'bottrap.admin.none'    => 'Nessun accesso alla trappola registrato finora.',
];
