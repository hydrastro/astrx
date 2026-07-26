<?php
declare(strict_types=1);

/** Chat module manifest. See {@see \AstrX\Module\ModuleRegistry}. */
return [
    'key'          => 'chat',
    'name'         => 'Chat',
    'version'      => '1.0.0',
    'nav'          => \AstrX\Chat\ChatNavContributor::class,
    'nav_defaults' => ['chat_nav' => false],
    'guards'       => [],
    'teardown'     => 'chat.down.sql',
];
