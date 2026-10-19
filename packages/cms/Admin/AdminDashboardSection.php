<?php
declare(strict_types=1);

namespace AstrX\Admin;

/**
 * Links a module adds to the admin dashboard (the admin root page), next to
 * core's (AdminService::NAV_PAGES). A module extension point (manifest
 * `extensions`); only ENABLED modules are asked.
 */
interface AdminDashboardSection
{
    /**
     * Page slug => label translation key. The slug's page is found through its
     * WORDING_<SLUG> key; '<label key>.desc' is shown under it.
     *
     * @return array<string,string>
     */
    public function adminSections(): array;
}
