<?php
declare(strict_types=1);

namespace AstrX\Module;

/**
 * An optional application module.
 *
 * A module is a class, so Composer can autoload it from wherever its package
 * lives. The application lists the modules it installs explicitly
 * (ApplicationModules::INSTALLED) and hands that list to ModuleRegistry.
 * Nothing scans directories, so a module whose code is present is not active
 * until the application lists it AND Modules.config.php switches it on.
 *
 * manifest() describes the module. See docs/MODULES.md for every key:
 * key, name, version, nav, nav_defaults, guards, policies, permissions,
 * controllers and teardown. It must be cheap and side-effect free: it is
 * read for disabled modules too (the grants editor and controller map need
 * their declarations).
 */
interface Module
{
    /** @return array<string, mixed> */
    public function manifest(): array;
}
