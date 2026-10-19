<?php
declare(strict_types=1);

namespace AstrX\User;

/**
 * Links a module adds to the user home page, next to Profile, Settings and
 * Logout. A module extension point (manifest `extensions`); only ENABLED
 * modules are asked.
 */
interface UserHomeSection
{
    /**
     * Page slug => label translation key. The slug's page is found through its
     * WORDING_<SLUG> key; '<label key>.desc', when it exists, is shown under it.
     *
     * @return array<string,string>
     */
    public function userHomeSections(): array;
}
