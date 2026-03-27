<?php
declare(strict_types=1);

namespace AstrX\Admin;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;

/**
 * Admin navigation registry.
 *
 * NAV_PAGES maps url_id slug → label translation key.
 * Order determines display order in the admin nav.
 */
final class AdminService
{
    public const array NAV_PAGES = [
        // ── Content management ──────────────────────────────────────────────
        'admin'          => 'admin.nav.home',
        'admin_news'     => 'admin.nav.news',
        'admin_comments' => 'admin.nav.comments',   // moderation + comment config
        'admin_users'    => 'admin.nav.users',       // user management + user config
        'admin_banlist'  => 'admin.nav.banlist',
        'admin_navbar'   => 'admin.nav.navbar',
        'admin_pages'    => 'admin.nav.pages',
        'admin_notes'    => 'admin.nav.notes',

        // ── Configuration ───────────────────────────────────────────────────
        'admin_config_system'  => 'admin.nav.config_system',  // core + routing + session + news
        'admin_config_access'  => 'admin.nav.config_access',  // grants + banlist routes
        'admin_config_captcha' => 'admin.nav.config_captcha',
        'admin_config_mail'    => 'admin.nav.config_mail',
        'admin_config_webmail' => 'admin.nav.config_webmail',

        // ── Audit ──────────────────────────────────────────────────────
        'admin_audit_log'      => 'admin.nav.audit_log',
    ];

    public function __construct(private readonly Gate $gate) {}

    public function isAdmin(): bool
    {
        return $this->gate->can(Permission::ADMIN_ACCESS);
    }
}