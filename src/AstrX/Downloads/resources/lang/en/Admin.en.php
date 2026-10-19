<?php
declare(strict_types=1);

/**
 * Admin — en: the Downloads module's keys (merged into the site's Admin catalog).
 */
return [
    // Signed downloads / release manifest editor
    'admin.downloads.heading'    => 'Signed release manifest',
    'admin.downloads.intro'      => 'Publish a signed list of your released files. The public /downloads page verifies the signature server-side against the key below and shows visitors whether it is valid.',
    'admin.downloads.manifest'   => 'Release manifest (file  SHA-256, one per line)',
    'admin.downloads.pubkey'     => 'ED25519 public key (base64)',
    'admin.downloads.sig'        => 'Detached signature of the manifest (base64)',
    'admin.downloads.hint_sign'  => 'Sign offline. Generate a key once (the private half never touches the server), then for each release: sign the manifest with LF (unix) line endings using your private key, and paste the base64 public key and detached signature here. A trailing newline is tolerated.',
    'admin.downloads.save'       => 'Save',
    'admin.downloads.saved'      => 'Release manifest saved.',
    'admin.downloads.cleared'    => 'Release manifest cleared.',
    'admin.downloads.bad_pubkey' => 'The public key must be base64 of a 32-byte ED25519 key.',
    'admin.downloads.bad_sig'    => 'The signature must be base64 of a 64-byte ED25519 signature.',
];
