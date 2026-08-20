<?php
declare(strict_types=1);

namespace AstrX\Module;

use AstrX\Config\Config;
use AstrX\Injector\Injector;

/**
 * Central switchboard for optional modules — manifest-driven.
 *
 * Each optional module ships a `module.php` manifest in its namespace directory
 * (src/AstrX/<Module>/module.php) declaring its key, display name, an optional
 * nav contributor + disabled-defaults, page guards, and a teardown file. This
 * registry DISCOVERS those manifests, so adding a module is "drop a manifest and
 * a config flag" — core (ContentManager / DefaultTemplateContext / NavbarHandler)
 * still names no module, and this class no longer hardcodes the module list.
 *
 * "Is module X on?" is resources/config/Modules.config.php (section 'Modules',
 * one bool per key, default ON so existing installs are unaffected). A DISABLED
 * module is never instantiated — its manifest's `nav_defaults` are merged instead.
 *
 * @phpstan-type Manifest array{key:string,name:string,version:string,nav:?string,nav_defaults:array<string,mixed>,guards:list<string>,teardown:?string}
 */
final class ModuleRegistry
{
    /** @var list<Manifest>|null Discovered manifests, loaded once per request. */
    private ?array $manifests = null;

    public function __construct(
        private readonly Config   $config,
        private readonly Injector $injector,
    ) {
        $this->config->loadModuleConfig('Modules');
    }

    /**
     * Whether an optional module is enabled.
     *
     * A key that ships a manifest but is missing from Modules.config.php
     * defaults ON, so an install whose config file predates a new module keeps
     * behaving as before; tools/check_modules.php fails the build on that gap,
     * so it cannot survive in the repository.
     *
     * A key with NO manifest is OFF. This is the rule ModulePageGuard already
     * applies to `page.module`, and the two have to agree: the guard fails
     * closed on an unrecognised owner while this method answered "enabled" for
     * the identical key, so the same typo hid a page in one place and left a
     * module's nav, guards and hooks switched on in the other.
     */
    public function enabled(string $key): bool
    {
        if (!in_array($key, $this->moduleKeys(), true)) {
            return false;
        }

        return $this->config->getConfigBool('Modules', $key, true);
    }

    /**
     * Every module that ships a manifest (enabled or not).
     *
     * @return list<string>
     */
    public function moduleKeys(): array
    {
        return array_map(static fn(array $m): string => $m['key'], $this->manifests());
    }

    /**
     * Manifest modules that are currently OFF. Used by NavbarHandler to drop nav
     * entries pointing at a disabled module's pages.
     *
     * @return list<string>
     */
    public function disabledModules(): array
    {
        $out = [];
        foreach ($this->manifests() as $m) {
            if (!$this->enabled($m['key'])) {
                $out[] = $m['key'];
            }
        }
        return $out;
    }

    /**
     * Context vars contributed by the nav-providing modules: the real values from
     * each ENABLED module's contributor, or its declared disabled-defaults. Merged
     * into the base context by DefaultTemplateContext.
     *
     * @return array<string,mixed>
     */
    public function navVars(): array
    {
        $out = [];
        foreach ($this->manifests() as $m) {
            if ($m['nav'] === null) {
                continue;
            }
            if ($this->enabled($m['key'])) {
                $contributor = $this->build($m['nav']);
                if ($contributor instanceof NavContributor) {
                    foreach ($contributor->vars() as $k => $v) {
                        $out[$k] = $v;
                    }
                    continue;
                }
            }
            foreach ($m['nav_defaults'] as $k => $v) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Page guards: the core module guard (hides any page whose module is off, via
     * page.module) first, then the guards ENABLED modules contribute.
     *
     * @return list<PageGuard>
     */
    public function pageGuards(): array
    {
        $out = [new ModulePageGuard($this)];
        foreach ($this->manifests() as $m) {
            if (!$this->enabled($m['key'])) {
                continue;
            }
            foreach ($m['guards'] as $class) {
                $guard = $this->build($class);
                if ($guard instanceof PageGuard) {
                    $out[] = $guard;
                }
            }
        }
        return $out;
    }

    /**
     * Discover + normalise the module manifests once per request.
     *
     * @return list<Manifest>
     */
    private function manifests(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $out = [];
        // Locate src/AstrX/<Module>/module.php via the CLASS_DIR constant, NOT
        // dirname(__DIR__): in COMPILED mode this class's code is loaded from the
        // bundle, so __DIR__ is build/ and dirname(__DIR__) would glob the repo
        // ROOT's */module.php — matching e.g. tools/module.php and running its CLI
        // guard. CLASS_DIR resolves to src/AstrX/ in every real entry point.
        $base = defined('CLASS_DIR')
            ? rtrim((string) constant('CLASS_DIR'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            : dirname(__DIR__) . DIRECTORY_SEPARATOR;
        $pattern = $base . '*' . DIRECTORY_SEPARATOR . 'module.php';
        foreach (glob($pattern) ?: [] as $file) {
            /** @var mixed $raw */
            $raw = require $file;
            $manifest = self::normalise($raw);
            if ($manifest !== null) {
                $out[] = $manifest;
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));

        $this->manifests = $out;
        return $out;
    }

    /**
     * Validate + fill defaults for a raw manifest array, or null if unusable.
     *
     * @return Manifest|null
     */
    private static function normalise(mixed $raw): ?array
    {
        if (!is_array($raw) || !isset($raw['key']) || !is_string($raw['key']) || $raw['key'] === '') {
            return null;
        }

        $name        = (isset($raw['name']) && is_string($raw['name'])) ? $raw['name'] : $raw['key'];
        $version     = (isset($raw['version']) && is_string($raw['version']) && $raw['version'] !== '') ? $raw['version'] : '0.0.0';
        $nav         = (isset($raw['nav']) && is_string($raw['nav']) && $raw['nav'] !== '') ? $raw['nav'] : null;
        $navDefaults = (isset($raw['nav_defaults']) && is_array($raw['nav_defaults'])) ? $raw['nav_defaults'] : [];
        $teardown    = (isset($raw['teardown']) && is_string($raw['teardown']) && $raw['teardown'] !== '') ? $raw['teardown'] : null;

        $guards = [];
        if (isset($raw['guards']) && is_array($raw['guards'])) {
            foreach ($raw['guards'] as $g) {
                if (is_string($g) && $g !== '') {
                    $guards[] = $g;
                }
            }
        }

        /** @var array<string,mixed> $navDefaults */
        return [
            'key'          => $raw['key'],
            'name'         => $name,
            'version'      => $version,
            'nav'          => $nav,
            'nav_defaults' => $navDefaults,
            'guards'       => $guards,
            'teardown'     => $teardown,
        ];
    }

    /** Build a contributor/guard through the injector, or null if it can't be built. */
    private function build(string $class): ?object
    {
        $result = $this->injector->getClass($class);
        if (!$result->isOk()) {
            return null;
        }
        $instance = $result->unwrap();
        return is_object($instance) ? $instance : null;
    }
}
