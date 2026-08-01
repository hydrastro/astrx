<?php
declare(strict_types=1);

/**
 * Two-factor management — user page — en locale. Keys mirror the it counterpart
 * 1:1 (check_lang_parity.php). Loaded for the 'twofactor' page.
 */
return [
    'twofactor.heading'          => 'Two-factor authentication',
    'twofactor.intro'            => 'Add a time-based one-time code (TOTP) from an authenticator app as a second factor on login.',
    'twofactor.status_on'        => 'Two-factor authentication is ON.',
    'twofactor.status_off'       => 'Two-factor authentication is off.',
    'twofactor.code'             => 'Code',
    'twofactor.begin'            => 'Set up two-factor',
    'twofactor.confirm'          => 'Confirm and enable',
    'twofactor.cancel'           => 'Cancel setup',
    'twofactor.disable'          => 'Turn off two-factor',
    'twofactor.setup_intro'      => 'Add this secret to your authenticator app (Aegis, FreeOTP, Google Authenticator, …), then enter a current code below to confirm.',
    'twofactor.secret_label'     => 'Secret key',
    'twofactor.uri_label'        => 'Or import this otpauth URI:',
    'twofactor.confirm_label'    => 'Enter a code to confirm',
    'twofactor.disable_label'    => 'Enter a current or recovery code to turn it off',
    'twofactor.recovery_heading' => 'Your recovery codes',
    'twofactor.recovery_intro'   => 'Save these somewhere safe now — they are shown only once. Each code works a single time if you lose your authenticator.',
    'twofactor.enabled'          => 'Two-factor authentication enabled.',
    'twofactor.disabled'         => 'Two-factor authentication disabled.',
    'twofactor.bad_code'         => 'That code was not valid. Please try again.',
];
