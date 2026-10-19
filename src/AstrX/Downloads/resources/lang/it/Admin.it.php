<?php
declare(strict_types=1);

/**
 * Admin — it: the Downloads module's keys (merged into the site's Admin catalog).
 */
return [
    // Editor manifest di rilascio firmato
    'admin.downloads.heading'    => 'Manifest di rilascio firmato',
    'admin.downloads.intro'      => 'Pubblica una lista firmata dei tuoi file rilasciati. La pagina pubblica /downloads verifica la firma lato server con la chiave qui sotto e mostra ai visitatori se è valida.',
    'admin.downloads.manifest'   => 'Manifest di rilascio (file  SHA-256, uno per riga)',
    'admin.downloads.pubkey'     => 'Chiave pubblica ED25519 (base64)',
    'admin.downloads.sig'        => 'Firma staccata del manifest (base64)',
    'admin.downloads.hint_sign'  => 'Firma offline. Genera una chiave una volta (la metà privata non tocca mai il server), poi per ogni rilascio: firma il manifest con terminatori di riga LF (unix) usando la tua chiave privata e incolla qui la chiave pubblica e la firma staccata in base64. Una newline finale è tollerata.',
    'admin.downloads.save'       => 'Salva',
    'admin.downloads.saved'      => 'Manifest di rilascio salvato.',
    'admin.downloads.cleared'    => 'Manifest di rilascio cancellato.',
    'admin.downloads.bad_pubkey' => 'La chiave pubblica deve essere in base64 di una chiave ED25519 di 32 byte.',
    'admin.downloads.bad_sig'    => 'La firma deve essere in base64 di una firma ED25519 di 64 byte.',
];
