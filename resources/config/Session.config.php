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

        // Server-side secret mixed into the per-session HKDF key derivation.
        // A stolen database row cannot be decrypted without this secret.
        //
        // Leave this EMPTY: when empty, a unique secret is generated per-install
        // on first run (SecureSessionHandler writes a random
        // .server_secret_generated file in the config dir) or you can set it
        // explicitly here via the installer (tools/install.php / setup wizard).
        // Generate one with: php -r "echo bin2hex(random_bytes(32));"
        // WARNING: changing this value invalidates ALL existing sessions.
        // NOTE: the old public value that once shipped here is now hard-ignored
        // by the handler, so it can never be used even if pasted back in.
        'server_secret' => 'c7daa3562226300f63cdd2b5d416abd2ff8648bdd19c9cf6ae9f15b0216753f6',

        // cipher: AES-256-CTR, HMAC: SHA-256 — hardcoded, not configurable
        // (changing these would silently corrupt existing encrypted sessions)

        // PRG
        'prg_token_key' => 'prg',
        'prg_token_regex' => '/^[\da-fA-F]{64}$/',

        // collision retry
        'max_sid_retries' => 8,

        // ── Session ID regeneration ───────────────────────────────────────────
        // Time-based regeneration interval per UserGroup name (seconds).
        // 0 = disabled for that group.
        // Keys are UserGroup::case names (e.g. 'ADMIN', 'MOD', 'USER', 'GUEST').
        // Future groups not listed here fall back to 'default_interval'.
        'regenerate_interval' => [
            'default' => 0,      // fallback for unlisted groups
            'GUEST'   => 0,      // guests have no elevated privileges
            'USER'    => 3600,   // 60 minutes
            'MOD'     => 900,    // 15 minutes
            'ADMIN'   => 900,    // 15 minutes
        ],

        // Seconds the old session row remains valid after regeneration.
        // Protects slow/Tor connections where two requests may be in-flight.
        'regenerate_grace_period' => 30,
    ],
];
