<?php
declare(strict_types=1);

namespace AstrX\BotTrap;

use AstrX\Module\Module;

/** Bot-trap module. See {@see \AstrX\Module\ModuleRegistry}. */
final class BotTrapModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'bottrap',
            'name'         => 'Bot trap',
            'version'      => '1.0.0',
            'nav'          => \AstrX\BotTrap\BotTrapNavContributor::class,
            'nav_defaults' => ['trap_enabled' => false, 'trap_url' => '', 'trap_link_text' => ''],
            'guards'       => [\AstrX\BotTrap\BotTrapPageGuard::class],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_trap' => \AstrX\BotTrap\Controller\AdminTrapController::class,
                'bot_trap' => \AstrX\BotTrap\Controller\BotTrapController::class,
            ],
            'teardown'     => 'bottrap.down.sql',
        ];
    }
}
