<?php
declare(strict_types=1);

/**
 * Pannello di stato della suite (admin) — locale it.
 *
 * Caricato da AdminSuiteController tramite loadDomain(langDir(), 'SuiteAdmin').
 * Le chiavi rispecchiano 1:1 la controparte en (check_lang_parity.php).
 */
return [
    'suiteadmin.heading'            => 'Stato della suite',
    'suiteadmin.intro'             => 'Salute e metriche principali in tempo reale dei quattro motori standalone di astrx-suite. Un backend non attivo è mostrato come DOWN e non compromette mai questa pagina.',
    'suiteadmin.up'                => 'ATTIVO',
    'suiteadmin.down'              => 'DOWN',
    'suiteadmin.col.engine'        => 'Motore',
    'suiteadmin.col.status'        => 'Stato',
    'suiteadmin.col.latency'       => 'Latenza',
    'suiteadmin.col.health'        => 'Salute',
    'suiteadmin.col.metrics'       => 'Metriche',
    'suiteadmin.col.control'       => 'Controllo',
    'suiteadmin.control.onion_seed' => 'Accetta seed onion (sotto)',
    'suiteadmin.control.none'      => 'Solo visualizzazione',
    'suiteadmin.seed.heading'      => 'Invia un seed onion',
    'suiteadmin.seed.intro'        => "Accoda un nuovo URL .onion al motore onioncrawler tramite il suo endpoint /add. È l'unica azione di scrittura esposta da un motore della suite.",
    'suiteadmin.seed.label'        => 'URL seed onion',
    'suiteadmin.seed.submit'       => 'Invia seed',
    'suiteadmin.seed.queued'       => 'Seed accettato e accodato per la scansione.',
    'suiteadmin.seed.duplicate'    => 'Questo seed è già noto al crawler.',
    'suiteadmin.seed.blocked'      => "L'host del seed è nella blocklist anti-abuso ed è stato rifiutato.",
    'suiteadmin.seed.invalid'      => 'Non è un indirizzo .onion valido.',
    'suiteadmin.seed.forbidden'    => "Il crawler ha rifiutato l'invio (il suo endpoint /add richiede autenticazione o è disabilitato).",
    'suiteadmin.seed.unreachable'  => 'Il motore onioncrawler non è raggiungibile.',
    'suiteadmin.seed.empty'        => 'Inserisci prima un URL seed onion.',
    'suiteadmin.seed.error'        => 'Impossibile inviare il seed.',
];
