<?php
declare(strict_types=1);

/**
 * Signed downloads / release manifest — public page — en locale. Keys mirror the
 * it counterpart 1:1 (check_lang_parity.php). Loaded for the 'downloads' page.
 */
return [
    'downloads.heading'      => 'Downloads',
    'downloads.intro'        => 'The manifest below lists each released file with its SHA-256 hash. Verify what you downloaded against this list, and check the signature before trusting it.',
    'downloads.none'         => 'No signed release manifest is currently published.',
    'downloads.sig_valid'    => 'Signature VALID — this manifest was signed by the operator key below.',
    'downloads.sig_invalid'  => 'Signature INVALID — do not trust this manifest. It does not match the published key.',
    'downloads.sig_unsigned' => 'This manifest is not signed. Treat the hashes as informational only.',
    'downloads.pubkey_label' => 'Operator signing key (ED25519, base64)',
    'downloads.verify_hint'  => 'Verify offline: sha256sum the files and compare, then verify the detached signature against the published key with a tool you trust.',
];
