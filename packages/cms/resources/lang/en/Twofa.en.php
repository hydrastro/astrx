<?php
declare(strict_types=1);

/**
 * Two-factor challenge — public page — en locale. Keys mirror the it counterpart
 * 1:1 (check_lang_parity.php). Loaded for the 'twofa' page.
 */
return [
    'twofa.heading'  => 'Two-factor verification',
    'twofa.intro'    => 'Enter the 6-digit code from your authenticator app to finish signing in.',
    'twofa.code'     => 'Authentication code',
    'twofa.hint'     => 'Lost your device? Enter one of your one-time recovery codes instead.',
    'twofa.verify'   => 'Verify',
    'twofa.bad_code' => 'That code was not valid. Please try again.',
    'twofa.locked'   => 'Too many incorrect codes. For your security, sign in again later.',
];
