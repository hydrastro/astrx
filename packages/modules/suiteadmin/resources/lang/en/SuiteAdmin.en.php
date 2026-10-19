<?php
declare(strict_types=1);

/**
 * Suite admin / status panel — en locale.
 *
 * Loaded by AdminSuiteController via loadDomain(langDir(), 'SuiteAdmin').
 * Keys mirror the it counterpart 1:1 (check_lang_parity.php).
 */
return [
    'suiteadmin.heading'            => 'Suite status',
    'suiteadmin.intro'             => 'Live health and key metrics of the four standalone astrx-suite engines. A backend that is down is shown as DOWN and never breaks this page.',
    'suiteadmin.up'                => 'UP',
    'suiteadmin.down'              => 'DOWN',
    'suiteadmin.col.engine'        => 'Engine',
    'suiteadmin.col.status'        => 'Status',
    'suiteadmin.col.latency'       => 'Latency',
    'suiteadmin.col.health'        => 'Health',
    'suiteadmin.col.metrics'       => 'Metrics',
    'suiteadmin.col.control'       => 'Control',
    'suiteadmin.control.onion_seed' => 'Accepts onion seeds (below)',
    'suiteadmin.control.none'      => 'Display only',
    'suiteadmin.seed.heading'      => 'Submit an onion seed',
    'suiteadmin.seed.intro'        => 'Queue a new .onion URL for the onioncrawler engine via its /add endpoint. This is the only write action any suite engine exposes.',
    'suiteadmin.seed.label'        => 'Onion seed URL',
    'suiteadmin.seed.submit'       => 'Submit seed',
    'suiteadmin.seed.queued'       => 'Seed accepted and queued for crawling.',
    'suiteadmin.seed.duplicate'    => 'That seed is already known to the crawler.',
    'suiteadmin.seed.blocked'      => 'That seed host is on the abuse blocklist and was rejected.',
    'suiteadmin.seed.invalid'      => 'That is not a valid .onion address.',
    'suiteadmin.seed.forbidden'    => 'The crawler refused the submission (its /add endpoint requires authentication or is disabled).',
    'suiteadmin.seed.unreachable'  => 'The onioncrawler engine is unreachable.',
    'suiteadmin.seed.empty'        => 'Enter an onion seed URL first.',
    'suiteadmin.seed.error'        => 'The seed could not be submitted.',
];
