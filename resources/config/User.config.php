<?php
declare(strict_types=1);

return [
    'UserService' => [
        'token_expiration_time' => 21600,
        'allow_register' => true,
        'allow_login_non_verified_users' => true,
        'require_email' => true,
        'require_recovery_email' => true,
        'require_display_name' => true,
        'require_birth_date' => false,
        'case_sensitive_usernames' => false,
        'minimum_age' => 0,
        'maximum_age' => 0,
        'login_captcha_type' => 2,
        'login_captcha_attempts' => 3,
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
    'AvatarService' => [
        'avatar_dir' => '/app/resources/avatar',
        'avatar_file_size' => 1048576,
        'use_identicons' => true,
    ],
];
