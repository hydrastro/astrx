<?php
declare(strict_types=1);

/**
 * Webmail module manifest — the in-browser mailbox client (WebmailController +
 * the webmail page + webmail_trusted_sender). The shared Mail backend
 * (src/AstrX/Mail, used by auth for password-reset mail) is core and stays on.
 *
 * No nav contributor/guard: the webmail navbar entry is a DB row NavbarHandler
 * drops when the module is off, and the core ModulePageGuard 404s its page.
 * This dir holds only the manifest for now; WebmailController still lives under
 * Controller/ (physical relocation is a later step). See {@see \AstrX\Module\ModuleRegistry}.
 */
return [
    'key'          => 'webmail',
    'name'         => 'Webmail',
    'version'      => '1.0.0',
    'nav'          => null,
    'nav_defaults' => [],
    'guards'       => [],
    'teardown'     => 'webmail.down.sql',
];
