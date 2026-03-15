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
 *
 * Authentication is handled by Gate::can(Permission::ADMIN_ACCESS) —
 * each controller calls this itself so the gate is the single source of truth.
 */
final class AdminService
{
    /**
     * Admin sub-page slugs → label keys.
     * Keys are the raw url_id values as stored in the page table.
     * These will be prefixed with WORDING_ for the i18n lookup.
     */
    public const array NAV_PAGES = [
        'admin'          => 'admin.nav.home',
        'admin_news'     => 'admin.nav.news',
        'admin_comments' => 'admin.nav.comments',
        'admin_users'    => 'admin.nav.users',
        'admin_banlist'  => 'admin.nav.banlist',
        'admin_navbar'   => 'admin.nav.navbar',
        'admin_pages'    => 'admin.nav.pages',
        'admin_notes'    => 'admin.nav.notes',
    ];

    public function __construct(private readonly Gate $gate) {}

    public function isAdmin(): bool
    {
        return $this->gate->can(Permission::ADMIN_ACCESS);
    }
}
