<?php

declare(strict_types = 1);

use AstrX\Admin\BanlistRepository;

/**
 * Banlist route configuration.
 * A "route" is a graduated penalty schedule for a particular offence type.
 * Each route has penalty rounds (0, 1, 2, …). On each offence:
 *   - If tries < max_tries for the current round, increment tries.
 *   - If tries >= max_tries, advance to the next round.
 * Fields per round:
 *   penalty    — ban duration in seconds (0 = permanent)
 *   max_tries  — how many times this round allows before escalating (0 = permanent)
 *   check_time — sliding window in seconds for counting tries (0 = no window)
 *   enabled    — whether this round is active
 */
return [
    'Banlist' => [
        'routes' => [
            BanlistRepository::ROUTE_PERMANENT => [
                0 => [
                    'penalty' => 0,
                    'max_tries' => 0,
                    'check_time' => 0,
                    'enabled' => true,
                ],
            ],

            BanlistRepository::ROUTE_BAD_COMMENT => [
                0 => [
                    'penalty' => 3 * 3600,          // 3 hours
                    'max_tries' => 3,
                    'check_time' => 3600,
                    'enabled' => true,
                ],
                1 => [
                    'penalty' => 6 * 3600,           // 6 hours
                    'max_tries' => 2,
                    'check_time' => 86400,
                    'enabled' => true,
                ],
                2 => [
                    'penalty' => 7 * 86400,           // 1 week
                    'max_tries' => 1,
                    'check_time' => 604800,
                    'enabled' => true,
                ],
                3 => [
                    'penalty' => 30 * 86400,           // 1 month
                    'max_tries' => 1,
                    'check_time' => 2592000,
                    'enabled' => true,
                ],
                4 => [
                    'penalty' => 0,                    // permanent
                    'max_tries' => 0,
                    'check_time' => 0,
                    'enabled' => true,
                ],
            ],

            BanlistRepository::ROUTE_FAILED_LOGIN => [
                0 => [
                    'penalty' => 300,                  // 5 minutes
                    'max_tries' => 20,
                    'check_time' => 3600,
                    'enabled' => true,
                ],
                1 => [
                    'penalty' => 3600,                 // 1 hour
                    'max_tries' => 15,
                    'check_time' => 86400,
                    'enabled' => true,
                ],
                2 => [
                    'penalty' => 86400,                // 1 day
                    'max_tries' => 15,
                    'check_time' => 604800,
                    'enabled' => true,
                ],
                3 => [
                    'penalty' => 604800,               // 1 week
                    'max_tries' => 15,
                    'check_time' => 2592000,
                    'enabled' => true,
                ],
                4 => [
                    'penalty' => 2592000,              // 1 month
                    'max_tries' => 10,
                    'check_time' => 2592000,
                    'enabled' => true,
                ],
            ],
        ],
    ],
];