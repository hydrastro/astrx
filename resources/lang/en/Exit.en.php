<?php
declare(strict_types=1);

/**
 * Off-site exit interstitial — public page — en locale. Keys mirror the it
 * counterpart 1:1 (check_lang_parity.php). Loaded for the 'exit' page.
 */
return [
    'exit.heading'     => 'Leaving this site',
    'exit.warning'     => 'You are about to follow a link off this hidden service. Opening it may reveal your IP address, leak a referrer, or expose you to an exit-node observer. Continue only if you trust the destination and understand the anonymity trade-off.',
    'exit.destination' => 'Destination',
    'exit.host'        => 'Host',
    'exit.continue'    => 'Continue to the external site',
    'exit.back'        => 'Go back',
    'exit.invalid'     => 'No valid external destination was provided.',
];
