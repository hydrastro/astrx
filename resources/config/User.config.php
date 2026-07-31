<?php
declare(strict_types=1);

return [
    'UserService' => [
        'token_expiration_time' => 21600,
        'allow_register' => true,
        'allow_login_non_verified_users' => true,
        'require_email' => true,
        'require_recovery_email' => true,
        // Verification email policy (fix114):
        //   true  → after registration, a verification email is sent. User
        //           starts with verified=0 and clicks the link to verify.
        //   false → no email is sent. User starts with verified=1 (auto-verified).
        //           Use this for closed-beta / invite-only sites where email
        //           verification adds friction without adding value.
        // Independent of require_email: if require_email=false and a user
        // registers without one, no email is sent regardless of this flag.
        'send_verification_email' => true,
        // Password recovery email policy (fix114):
        //   true  → recovery requests trigger an email with a reset link.
        //   false → recovery requests silently succeed but no email is sent
        //           (admin must intervene manually). Useful for sites where
        //           recovery is handled out-of-band.
        'send_password_reset_email' => true,
        'require_display_name' => true,
        'require_birth_date' => false,
        'case_sensitive_usernames' => false,
        'minimum_age' => 0,
        'maximum_age' => 0,
        'login_captcha_type' => 2,
        'login_captcha_attempts' => 3,
        // Brute-force lockout (fix M4): after this many consecutive failed
        // logins the account is temporarily locked for `login_lockout_cooldown`
        // seconds. Set the threshold to 0 to disable the feature entirely.
        'login_lockout_threshold' => 10,
        'login_lockout_cooldown' => 900,
        'register_captcha_type' => 0,
        'recover_captcha_type' => 0,
        'remember_me_time' => 2592000,
        'username_regex' => [
            1 => [
                'regex' => '/^[a-zA-Z0-9]{1,634}$/',
                'enabled' => true,
                'checking_for' => false,
                'message' => 'Username must be 1-64 alphanumeric characters.',
            ],
        ],
        'password_regex' => [],
    ],
    // ── Registration legal agreements ───────────────────────────────────────
    // enabled = true: checkbox is shown AND required.
    // enabled = false: checkbox is NOT shown (field is ignored on submission).
    // url: if non-empty, the label becomes a link to this URL.
    'RegisterConsent' => [
        'require_terms'      => false,
        'terms_url'          => '',
        'require_data_usage' => false,
        'data_usage_url'     => '',
    ],
    // ── Invite-only registration ────────────────────────────────────────────
    // require_invite = true: the register form shows an "Invite code" field and
    // registration is refused (no account created) unless a valid, unused,
    // admin-issued invite code is supplied. Codes are minted from the admin
    // "Invitations" page and are single-use. Read via getConfigBool('Invite', …)
    // by InviteService::requireInvite(); a getConfig-read section (like
    // RegisterConsent above) so it needs no #[InjectConfig] setter.
    'Invite' => [
        'require_invite' => false,
    ],

    'AvatarService' => [
        'avatar_dir' => '/app/resources/avatar',
        'avatar_file_size' => 1048576,
        'use_identicons' => true,
    ],
    'EmailService' => [
        // Absolute base URL for links in emails. Without this, emails contain
        // relative URLs which break when opened from a mail client. Set this
        // to your public site URL (e.g. 'https://example.com' or, for Tor,
        // 'http://abc123.onion').
        'site_url'  => getenv('SITE_URL')  ?: '',
        // Site name used in greetings and signatures.
        'site_name' => getenv('SITE_NAME') ?: 'AstrX',
    ],
];
