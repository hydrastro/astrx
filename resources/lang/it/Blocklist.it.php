<?php
declare(strict_types=1);

/**
 * Editor della blocklist (admin) — locale it.
 *
 * Caricato da AdminBlocklistController tramite loadDomain(langDir(), 'Blocklist').
 * Le chiavi rispecchiano 1:1 la controparte en (check_lang_parity.php).
 */
return [
    'blocklist.heading'          => 'Editor della blocklist',
    'blocklist.intro'            => 'Aggiungi voci alla blocklist anti-abuso dei motori della suite. Le voci vengono inviate sulla rete locale a ciascun motore e hanno effetto alla successiva scansione/indicizzazione.',
    'blocklist.onion.heading'    => 'Blocklist del crawler onion',
    'blocklist.onion.intro'      => 'Blocca un host onion o una parola chiave dal motore onioncrawler (POST /blocklist).',
    'blocklist.torrent.heading'  => 'Blocklist dell\'indicizzatore torrent',
    'blocklist.torrent.intro'    => 'Blocca un infohash torrent o una parola chiave dal motore torrentds (POST /api/block).',
    'blocklist.kind_label'       => 'Tipo',
    'blocklist.value_label'      => 'Valore',
    'blocklist.submit'           => 'Aggiungi alla blocklist',
    'blocklist.kind.host'        => 'Host onion',
    'blocklist.kind.keyword'     => 'Parola chiave',
    'blocklist.kind.infohash'    => 'Infohash',
    'blocklist.target.onion'     => 'crawler onion',
    'blocklist.target.torrent'   => 'indicizzatore torrent',
    'blocklist.added'            => 'Voce aggiunta alla blocklist del {target}.',
    'blocklist.duplicate'        => 'Quella voce è già nella blocklist del {target}.',
    'blocklist.forbidden'        => 'Il {target} ha rifiutato la richiesta (token admin errato o endpoint di controllo disabilitato).',
    'blocklist.invalid'          => 'Il {target} ha rifiutato quella voce come non valida.',
    'blocklist.empty'            => 'Inserisci prima un valore da bloccare sul {target}.',
    'blocklist.unconfigured'     => 'Nessun token admin è configurato per il {target}, quindi la voce non è stata inviata.',
    'blocklist.unreachable'      => 'Il motore {target} non è raggiungibile.',
    'blocklist.error'            => 'Impossibile aggiungere la voce alla blocklist del {target}.',
    'blocklist.invalid_kind'     => 'Quel tipo non è valido per il {target}.',
    'blocklist.invalid_infohash' => 'Non è un infohash valido (40 o 64 caratteri esadecimali) per il {target}.',
];
