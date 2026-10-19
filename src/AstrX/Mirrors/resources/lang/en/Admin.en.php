<?php
declare(strict_types=1);

/**
 * Admin — en: the Mirrors module's keys (merged into the site's Admin catalog).
 */
return [
    // Onion mirrors
    'admin.mirrors.heading'     => 'Onion mirrors / anti-phishing',
    'admin.mirrors.intro'       => 'Declare your canonical .onion (emitted as an Onion-Location header) and the mirror list shown on the public /mirrors page. Paste an offline-signed statement of the addresses so visitors can verify them out of band.',
    'admin.mirrors.primary'     => 'Canonical .onion URL (for Onion-Location)',
    'admin.mirrors.list'        => 'Mirror list (one URL per line)',
    'admin.mirrors.signed'      => 'Signed statement of the canonical addresses',
    'admin.mirrors.save'        => 'Save',
    'admin.mirrors.saved'       => 'Mirror settings saved.',
    'admin.mirrors.bad_primary' => 'The canonical URL must be an http(s) .onion address.',
];
