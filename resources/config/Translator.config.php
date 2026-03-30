<?php

declare(strict_types = 1);

return [
    'Session' => [
        'use_cookies' => true,

        // sid length in bytes for random_bytes (128 bytes => 256 hex chars)
        'sid_bytes' => 128,

        // routing-layer pattern only (optional)
        'session_id_regex' => '/^[\da-fA-F]{256}$/',

        // encryption policy
        'encrypt' => true,

        // cipher: AES-256-CTR, HMAC: SHA-256 — hardcoded, not configurable
        // (changing these would silently corrupt existing encrypted sessions)

        // PRG
        'prg_token_key' => 'prg',
        'prg_token_regex' => '/^[\da-fA-F]{64}$/',

        // collision retry
        'max_sid_retries' => 8,
    ],
];
