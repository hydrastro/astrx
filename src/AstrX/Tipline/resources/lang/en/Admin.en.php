<?php
declare(strict_types=1);

/**
 * Admin — en: the Tipline module's keys (merged into the site's Admin catalog).
 */
return [
    // Encrypted tip line
    'admin.tipline.heading'     => 'Anonymous tip line',
    'admin.tipline.intro'       => 'Set the sealed-box public key that incoming tips are encrypted to. Tips are stored as ciphertext only; reading them is done offline so the secret key never touches this server.',
    'admin.tipline.pubkey'      => 'Sealed-box public key (base64)',
    'admin.tipline.hint_keygen' => 'Generate a keypair offline: php tools/tipline.php keygen. Paste the public key here; keep the secret key off this machine.',
    'admin.tipline.hint_decrypt'=> 'To read tips, export the ciphertext and run: php tools/tipline.php decrypt <secret-key-file>. Do not paste your secret key into any web form.',
    'admin.tipline.save'        => 'Save public key',
    'admin.tipline.saved'       => 'Tip-line public key saved.',
    'admin.tipline.bad_pubkey'  => 'The public key must be base64 of a 32-byte sealed-box key.',
    'admin.tipline.queue'       => 'Sealed tips',
    'admin.tipline.time'        => 'Received',
    'admin.tipline.sealed'      => 'Ciphertext (sealed)',
    'admin.tipline.none'        => 'No tips have been received yet.',
    'admin.tipline.delete'      => 'Delete',
    'admin.tipline.deleted'     => 'Tip deleted.',
    'admin.tipline.purge'       => 'Shred all tips',
    'admin.tipline.purged'      => 'All tips shredded.',
];
