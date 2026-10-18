<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Module\Module;

/** Chat module. See {@see \AstrX\Module\ModuleRegistry}. */
final class ChatModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'chat',
            'name'         => 'Chat',
            'version'      => '1.0.0',
            'nav'          => \AstrX\Chat\ChatNavContributor::class,
            'nav_defaults' => ['chat_nav' => false],
            'guards'       => [],
            // Registered with the Gate only while chat is enabled.
            'policies'     => [\AstrX\Chat\ChatMessageResource::class => \AstrX\Chat\ChatPolicy::class],
            // Permission enums this module declares (listed in the grants editor).
            'permissions'  => [\AstrX\Chat\ChatPermission::class],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_chat_filters' => \AstrX\Chat\Controller\AdminChatFiltersController::class,
                'admin_config_chat' => \AstrX\Chat\Controller\AdminConfigChatController::class,
                'chat_admin' => \AstrX\Chat\Controller\ChatAdminController::class,
                'chat' => \AstrX\Chat\Controller\ChatController::class,
                'chat_file' => \AstrX\Chat\Controller\ChatFileController::class,
                'chat_help' => \AstrX\Chat\Controller\ChatHelpController::class,
                'chat_login' => \AstrX\Chat\Controller\ChatLoginController::class,
                'chat_pm' => \AstrX\Chat\Controller\ChatPmController::class,
                'chat_settings' => \AstrX\Chat\Controller\ChatSettingsController::class,
                'chat_stream' => \AstrX\Chat\Controller\ChatStreamController::class,
                'chat_users' => \AstrX\Chat\Controller\ChatUsersController::class,
                'chat_wait' => \AstrX\Chat\Controller\ChatWaitController::class,
            ],
            'teardown'     => 'chat.down.sql',
        ];
    }
}
