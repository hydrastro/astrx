<?php
declare(strict_types=1);

/** Bot-trap module manifest. See {@see \AstrX\Module\ModuleRegistry}. */
return [
    'key'          => 'bottrap',
    'name'         => 'Bot trap',
    'version'      => '1.0.0',
    'nav'          => \AstrX\BotTrap\BotTrapNavContributor::class,
    'nav_defaults' => ['trap_enabled' => false, 'trap_url' => '', 'trap_link_text' => ''],
    'guards'       => [\AstrX\BotTrap\BotTrapPageGuard::class],
    'teardown'     => 'bottrap.down.sql',
];
