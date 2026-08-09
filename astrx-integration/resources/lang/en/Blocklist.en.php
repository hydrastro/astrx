<?php
declare(strict_types=1);

/**
 * Blocklist editor (admin) — en locale.
 *
 * Loaded by AdminBlocklistController via loadDomain(langDir(), 'Blocklist').
 * Keys mirror the it counterpart 1:1 (check_lang_parity.php).
 */
return [
    'blocklist.heading'          => 'Blocklist editor',
    'blocklist.intro'            => 'Add abuse-blocklist entries to the suite engines. Entries are pushed over the local network to each engine and take effect on its next crawl/index pass.',
    'blocklist.onion.heading'    => 'Onion crawler blocklist',
    'blocklist.onion.intro'      => 'Block an onion host or a keyword from the onioncrawler engine (POST /blocklist).',
    'blocklist.torrent.heading'  => 'Torrent indexer blocklist',
    'blocklist.torrent.intro'    => 'Block a torrent infohash or a keyword from the torrentds engine (POST /api/block).',
    'blocklist.kind_label'       => 'Kind',
    'blocklist.value_label'      => 'Value',
    'blocklist.submit'           => 'Add to blocklist',
    'blocklist.kind.host'        => 'Onion host',
    'blocklist.kind.keyword'     => 'Keyword',
    'blocklist.kind.infohash'    => 'Infohash',
    'blocklist.target.onion'     => 'onion crawler',
    'blocklist.target.torrent'   => 'torrent indexer',
    'blocklist.added'            => 'Entry added to the {target} blocklist.',
    'blocklist.duplicate'        => 'That entry is already on the {target} blocklist.',
    'blocklist.forbidden'        => 'The {target} refused the request (the admin token is wrong or the control endpoint is disabled).',
    'blocklist.invalid'          => 'The {target} rejected that entry as invalid.',
    'blocklist.empty'            => 'Enter a value to block on the {target} first.',
    'blocklist.unconfigured'     => 'No admin token is configured for the {target}, so the entry was not sent.',
    'blocklist.unreachable'      => 'The {target} engine is unreachable.',
    'blocklist.error'            => 'The entry could not be added to the {target} blocklist.',
    'blocklist.invalid_kind'     => 'That kind is not valid for the {target}.',
    'blocklist.invalid_infohash' => 'That is not a valid infohash (40 or 64 hex characters) for the {target}.',
];
