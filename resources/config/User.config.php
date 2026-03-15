<?php
declare(strict_types=1);

use AstrX\User\UserService;

return [
    'UserService' => [
        // Token lifetime for email actions (seconds). Default: 6 hours.
        'token_expiration_time'          => 3600 * 6,

        // Registration
        'allow_register'                 => true,
        'allow_login_non_verified_users' => true,

        // Required fields
        'require_email'                  => true,   // mailbox local-part
        'require_recovery_email'         => true,   // full recovery address
        'require_display_name'           => true,
        'require_birth_date'             => false,

        // Username uniqueness is always case-insensitive in queries;
        // this flag makes the COMPARISON case-sensitive (rarely needed).
        'case_sensitive_usernames'       => false,

        // Age limits in seconds. 0 = disabled.
        // 568024668 ≈ 18 years in seconds.
        'minimum_age'                    => 0,
        'maximum_age'                    => 0,

        // Captcha display policy per form:
        //   0 = CAPTCHA_SHOW_ALWAYS
        //   1 = CAPTCHA_SHOW_NEVER
        //   2 = CAPTCHA_SHOW_ON_X_FAILED_ATTEMPTS (login only)
        'login_captcha_type'             => UserService::CAPTCHA_SHOW_ON_X_FAILED,
        'login_captcha_attempts'         => 3,
        'register_captcha_type'          => UserService::CAPTCHA_SHOW_ALWAYS,
        'recover_captcha_type'           => UserService::CAPTCHA_SHOW_ALWAYS,

        // "Remember me" cookie duration in seconds. Default: 30 days.
        'remember_me_time'               => 60 * 60 * 24 * 30,

        // Validation regex arrays. Each entry:
        //   regex        — PCRE pattern
        //   enabled      — whether this rule is active
        //   checking_for — true = fail if pattern MATCHES, false = fail if pattern does NOT match
        //   message      — error detail string (passed to UserDiagnostic::detail)
        'username_regex' => [
            1 => [
                'regex'        => '/^[a-zA-Z0-9]{1,64}$/',
                'enabled'      => true,
                'checking_for' => false,
                'message'      => 'Username must be 1-64 alphanumeric characters.',
            ],
        ],
        'password_regex' => [],
    ],

    'AvatarService' => [
        // Absolute path to the avatar storage directory.
        // Defaults to a sibling of TEMPLATE_DIR if not set.
        'avatar_dir'       => defined('TEMPLATE_DIR') ? dirname(TEMPLATE_DIR) . '/avatars' : 'avatars',
        'avatar_file_size' => 1048576,   // 1 MB
        'use_identicons'   => true,
    ],
];