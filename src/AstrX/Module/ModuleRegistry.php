<?php
declare(strict_types=1);

namespace AstrX\Module;

use AstrX\Config\Config;
use AstrX\Injector\Injector;

/**
 * Central switchboard for optional modules.
 *
 * The application hands this registry the module classes it installs
 * (ApplicationModules::INSTALLED). Nothing is discovered by scanning
 * directories, so modules can live anywhere Composer autoloads from. Each
 * module's manifest() declares its key, display name, an optional nav
 * contributor + disabled-defaults, page guards, resource policies (resource
 * class => policy class), page controllers (page file_name => controller
 * class), permission enums, and a teardown file.
 *
 * This class is generic infrastructure: it hands out the CLASS NAMES / instances
 * a manifest declares and never imports the interfaces they implement (page
 * guards belong to the CMS, policies to the security layer). The consumer that
 * knows the interface checks it. Core (ContentManager / DefaultTemplateContext /
 * NavbarHandler) names no module.
 *
 * Four states, kept apart on purpose:
 *   - present: the module's code is autoloadable — means nothing by itself;
 *   - installed: listed in the constructor's $modules;
 *   - enabled: installed AND `true` in Modules.config.php (section 'Modules');
 *     a module with no flag there is OFF;
 *   - migrated: its tables exist (tools/install.php / tools/module.php).
 * A DISABLED module is never instantiated beyond its manifest — its
 * `nav_defaults` are merged instead.
 *
 * @phpstan-type Manifest array{key:string,name:string,version:string,nav:?string,nav_defaults:array<string,mixed>,guards:list<string>,policies:array<string,string>,controllers:array<string,string>,permissions:list<string>,teardown:?string}
 */
final class ModuleRegistry
{
    /** @var list<Manifest>|null Normalised manifests, built once per request. */
    private ?array $manifests = null;

    /**
     * @param list<string> $modules Installed module classes (each implements
     *        Module). Empty — e.g. a container that was never told — means no
     *        modules, not "whatever is on disk".
     */
    public function __construct(
        private readonly Config   $config,
        private readonly Injector $injector,
        private readonly array    $modules = [],
    ) {
        $this->config->loadModuleConfig('Modules');
    }

    /**
     * Whether an optional module is enabled.
     *
     * Only an installed module whose Modules.config.php flag is `true` is on.
     * A missing flag means OFF: being installed (or merely present on disk)
     * must not switch a module on. tools/check_modules.php fails the build when
     * an installed module has no flag, so the repository never relies on this
     * default.
     *
     * A key that is not installed is OFF. This is the rule ModulePageGuard already
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

        return $this->config->getConfigBool('Modules', $key, false);
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
     * Instances of the `guards` declared by ENABLED modules, built through the
     * injector. The caller (the CMS page pipeline) filters them by its own guard
     * interface and prepends its module-ownership guard; see
     * \AstrX\Page\ModulePageGuard.
     *
     * @return list<object>
     */
    public function enabledGuards(): array
    {
        $out = [];
        foreach ($this->manifests() as $m) {
            if (!$this->enabled($m['key'])) {
                continue;
            }
            foreach ($m['guards'] as $class) {
                $guard = $this->build($class);
                if ($guard !== null) {
                    $out[] = $guard;
                }
            }
        }
        return $out;
    }

    /**
     * Resource policies declared by ENABLED modules: resource class => policy
     * class. Registering them with the Gate is the composition root's job, so
     * a disabled module's policy is never registered (and Gate denies any
     * resource-scoped check for a resource nobody rules on).
     *
     * @return array<string,string>
     */
    public function enabledPolicies(): array
    {
        $out = [];
        foreach ($this->manifests() as $m) {
            if (!$this->enabled($m['key'])) {
                continue;
            }
            foreach ($m['policies'] as $resource => $policy) {
                $out[$resource] = $policy;
            }
        }
        return $out;
    }

    /**
     * Page controllers declared by EVERY module manifest, enabled or not:
     * page file_name => controller class. Whether a disabled module's page is
     * served is ModulePageGuard's call (it 404s it before dispatch); the class
     * map itself is static knowledge. See \AstrX\Site\ControllerMap.
     *
     * @return array<string,string>
     */
    public function controllers(): array
    {
        $out = [];
        foreach ($this->manifests() as $m) {
            foreach ($m['controllers'] as $fileName => $class) {
                $out[$fileName] = $class;
            }
        }
        return $out;
    }

    /**
     * Permission enum classes declared by EVERY module manifest, enabled or
     * not. The grants editor must list a disabled module's permissions too, or
     * saving would drop that module's grants. See \AstrX\Auth\PermissionRegistry.
     *
     * @return list<string>
     */
    public function permissionEnums(): array
    {
        $out = [];
        foreach ($this->manifests() as $m) {
            foreach ($m['permissions'] as $enum) {
                $out[] = $enum;
            }
        }
        return $out;
    }

    /**
     * Build + normalise the installed modules' manifests once per request.
     *
     * @return list<Manifest>
     */
    private function manifests(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $out  = [];
        $keys = [];
        foreach ($this->modules as $class) {
            if (!class_exists($class) || !is_subclass_of($class, Module::class)) {
                throw new \LogicException("Installed module {$class} is not a class implementing " . Module::class . '.');
            }
            $manifest = self::normalise((new $class())->manifest());
            if ($manifest === null) {
                throw new \LogicException("Module {$class} has an unusable manifest (it needs a non-empty 'key').");
            }
            if (isset($keys[$manifest['key']])) {
                throw new \LogicException("Modules {$keys[$manifest['key']]} and {$class} both use the key '{$manifest['key']}'.");
            }
            $keys[$manifest['key']] = $class;
            $out[] = $manifest;
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

        $policies = [];
        if (isset($raw['policies']) && is_array($raw['policies'])) {
            foreach ($raw['policies'] as $resource => $policy) {
                if (is_string($resource) && $resource !== '' && is_string($policy) && $policy !== '') {
                    $policies[$resource] = $policy;
                }
            }
        }

        $controllers = [];
        if (isset($raw['controllers']) && is_array($raw['controllers'])) {
            foreach ($raw['controllers'] as $fileName => $class) {
                if (is_string($fileName) && $fileName !== '' && is_string($class) && $class !== '') {
                    $controllers[$fileName] = $class;
                }
            }
        }

        $permissions = [];
        if (isset($raw['permissions']) && is_array($raw['permissions'])) {
            foreach ($raw['permissions'] as $enum) {
                if (is_string($enum) && $enum !== '') {
                    $permissions[] = $enum;
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
            'policies'     => $policies,
            'controllers'  => $controllers,
            'permissions'  => $permissions,
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
