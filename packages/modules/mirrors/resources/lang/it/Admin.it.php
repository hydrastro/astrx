<?php
declare(strict_types=1);

/**
 * Admin — it: the Mirrors module's keys (merged into the site's Admin catalog).
 */
return [
    // Mirror onion
    'admin.mirrors.heading'     => 'Mirror onion / anti-phishing',
    'admin.mirrors.intro'       => 'Dichiara il tuo .onion canonico (emesso come header Onion-Location) e la lista dei mirror mostrata sulla pagina pubblica /mirrors. Incolla una dichiarazione firmata offline degli indirizzi affinché i visitatori possano verificarli fuori banda.',
    'admin.mirrors.primary'     => 'URL .onion canonico (per Onion-Location)',
    'admin.mirrors.list'        => 'Lista mirror (un URL per riga)',
    'admin.mirrors.signed'      => 'Dichiarazione firmata degli indirizzi canonici',
    'admin.mirrors.save'        => 'Salva',
    'admin.mirrors.saved'       => 'Impostazioni mirror salvate.',
    'admin.mirrors.bad_primary' => 'L URL canonico deve essere un indirizzo .onion http(s).',
];
