<?php
declare(strict_types=1);

namespace AstrX\Webmail;

use AstrX\Module\Module;

/**
 * Webmail module — the in-browser mailbox client (WebmailService,
 * WebmailController, the webmail page and webmail_trusted_sender). The shared
 * Mail library (Mailer, ImapClient, MailboxManager) is not part of it.
 *
 * No nav contributor/guard: the webmail navbar entry is a DB row NavbarHandler
 * drops when the module is off, and the core ModulePageGuard 404s its page.
 * See {@see \AstrX\Module\ModuleRegistry}.
 */
final class WebmailModule implements Module
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'key'          => 'webmail',
            'name'         => 'Webmail',
            'version'      => '1.0.0',
            'nav'          => null,
            'nav_defaults' => [],
            'guards'       => [],
            // Permission enums this module declares (listed in the grants editor).
            'permissions'  => [\AstrX\Webmail\WebmailPermission::class],
            // Page file_name => controller. Resolved by Site\ControllerMap.
            'controllers'  => [
                'admin_config_webmail' => \AstrX\Webmail\Controller\AdminConfigWebmailController::class,
                'webmail' => \AstrX\Webmail\Controller\WebmailController::class,
            ],
            'teardown'     => 'webmail.down.sql',
        ];
    }
}
