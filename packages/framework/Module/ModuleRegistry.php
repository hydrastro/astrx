<?php
declare(strict_types=1);

namespace AstrX\Module;

use AstrX\Config\Config;
use AstrX\Injector\Injector;
use AstrX\Support\ResourceRoots;

/**
 * Central switchboard for optional modules.
 *
 * The application hands this registry the module classes it installs
 * (ApplicationModules::INSTALLED). Nothing is discovered by scanning
 * directories, so modules can live anywhere Composer autoloads from. Each
 * module's manifest() declares its key, display name, an optional nav
 * contributor + disabled-defaults, page guards, resource policies (resource
 * class => policy class), page controllers (page file_name => controller
 * class), permission enums, a teardown file, the directory holding its
 * templates, translations, default config and SQL (`resources`, relative to
 * the module class's own directory), the core extension points it implements
 * (`extensions`: interface => classes) and the partials it adds to the layout's
 * slots (`layout`: slot => partial names).
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
 * @phpstan-type Manifest array{key:string,name:string,version:string,nav:?string,nav_defaults:array<string,mixed>,guards:list<string>,policies:array<string,string>,controllers:array<string,string>,permissions:list<string>,teardown:?string,resources:?string,extensions:array<string,list<string>>,layout:array<string,list<string>>}
 */
final class ModuleRegistry
{
    /**
     * The layout's slots a module may add partials to (manifest `layout`):
     * default.html renders each slot's list as {{#<slot>_partials}}…
     */
    public const LAYOUT_SLOTS = ['nav', 'footer'];

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
     * Whether a page owned by $pageModule (the `page.module` column) is live.
     *
     * A core page (empty owner) always is. Otherwise the owner must be an
     * INSTALLED module that is enabled; the value is matched case- and
     * whitespace-insensitively, and an owner no installed module claims — a
     * typo, or a module that was uninstalled while its page rows stayed — is
     * not live (fail closed). The page gate, the navbar and the JS manifest
     * all ask this one question, so a page is never linked where it 404s.
     */
    public function pageOwnerEnabled(string $pageModule): bool
    {
        $raw = strtolower(trim($pageModule));
        if ($raw === '') {
            return true;
        }
        foreach ($this->moduleKeys() as $k) {
            if (strtolower(trim($k)) === $raw) {
                return $this->enabled($k);
            }
        }
        return false;
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
     * Instances of the classes modules declare for a core extension point,
     * built through the injector, in module-key order.
     *
     * An extension point is an interface owned by the core feature that asks
     * the question — Retention\RetentionSource ("which data do you keep?"),
     * Page\SitemapSource, User\LoginListener, … A module answers it by listing
     * classes under that interface in its manifest's `extensions`. Core asks
     * this method and never names a module.
     *
     * Only ENABLED modules answer, unless $installedToo: a question about data
     * that exists whether or not the module is switched on (retention) must ask
     * disabled modules too.
     *
     * @template T of object
     * @param class-string<T> $interface
     * @return list<T>
     */
    public function extensions(string $interface, bool $installedToo = false): array
    {
        $out = [];
        foreach ($this->manifests() as $m) {
            if (!$installedToo && !$this->enabled($m['key'])) {
                continue;
            }
            foreach ($m['extensions'][$interface] ?? [] as $class) {
                $instance = $this->build($class);
                if ($instance instanceof $interface) {
                    $out[] = $instance;
                }
            }
        }
        return $out;
    }

    /**
     * The partials ENABLED modules add to a layout slot, in module-key order:
     * 'nav' (the navigation stack under the header) and 'footer'. The layout
     * renders each one (`{{#nav_partials}}{{> .}}{{/nav_partials}}`); a partial
     * decides for itself whether it shows on the current page.
     *
     * @return list<string>
     */
    public function layoutPartials(string $slot): array
    {
        $out = [];
        foreach ($this->manifests() as $m) {
            if ($this->enabled($m['key'])) {
                foreach ($m['layout'][$slot] ?? [] as $partial) {
                    $out[] = $partial;
                }
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
        return $this->manifests ??= self::buildManifests($this->modules);
    }

    /**
     * Instantiate, validate and normalise the manifests of $modules, sorted by
     * key. Throws on a class that is not a Module or a duplicate key: a broken
     * module list must fail loudly, not run with a module silently missing.
     *
     * @param list<string> $modules
     * @return list<Manifest>
     */
    private static function buildManifests(array $modules): array
    {
        $out  = [];
        $keys = [];
        foreach ($modules as $class) {
            if (!class_exists($class) || !is_subclass_of($class, Module::class)) {
                throw new \LogicException("Installed module {$class} is not a class implementing " . Module::class . '.');
            }
            $manifest = self::normalise((new $class())->manifest(), $class);
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

        return $out;
    }

    /**
     * The resource directories of every installed module, enabled or not (a
     * disabled module's translations still label its grants in the grants
     * editor, and its templates cost nothing while its pages 404).
     */
    public function resourceRoots(): ResourceRoots
    {
        return self::rootsOf($this->manifests());
    }

    /**
     * Same as resourceRoots(), for callers that have no Config yet: the
     * composition root needs the roots to build Config itself, and the tools
     * need them without booting the application.
     *
     * @param list<string> $modules installed module classes
     */
    public static function resourceRootsFor(array $modules): ResourceRoots
    {
        return self::rootsOf(self::buildManifests($modules));
    }

    /**
     * Module key => that module's resources directory, for the installed
     * modules that have one (the tools that act on ONE module — purge — need
     * to know whose directory is whose).
     *
     * @param list<string> $modules installed module classes
     * @return array<string,string>
     */
    public static function resourceDirsByKey(array $modules): array
    {
        $out = [];
        foreach (self::buildManifests($modules) as $m) {
            if ($m['resources'] !== null) {
                $out[$m['key']] = $m['resources'];
            }
        }
        return $out;
    }

    /** @param list<Manifest> $manifests */
    private static function rootsOf(array $manifests): ResourceRoots
    {
        $dirs = [];
        foreach ($manifests as $m) {
            if ($m['resources'] !== null) {
                $dirs[] = $m['resources'];
            }
        }
        return new ResourceRoots($dirs);
    }

    /**
     * The directory a module class lives in, or null if it cannot be found.
     *
     * Normally the class's own file. The compiled single-file build evaluates
     * every class from a string, so reflection reports no real file there; the
     * bundle then names the source path it was built from, relative to the
     * project root (INDEX_DIR), where the module's resources are deployed.
     */
    private static function classDir(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }
        $file = (new \ReflectionClass($class))->getFileName();
        if (is_string($file) && is_file($file)) {
            return dirname($file);
        }

        // The bundle is generated, so it is named as a string, never imported.
        $sourcePath = 'AstrX\\Compiled\\Bundle::sourcePath';
        if (is_callable($sourcePath)) {
            $rel = $sourcePath($class);
            if (is_string($rel) && $rel !== '') {
                return rtrim(\AstrX\Support\indexDir(), '/\\') . DIRECTORY_SEPARATOR . dirname($rel);
            }
        }
        return null;
    }

    /**
     * Validate + fill defaults for a raw manifest array, or null if unusable.
     *
     * @return Manifest|null
     */
    private static function normalise(mixed $raw, string $class): ?array
    {
        if (!is_array($raw) || !isset($raw['key']) || !is_string($raw['key']) || $raw['key'] === '') {
            return null;
        }

        $name        = (isset($raw['name']) && is_string($raw['name'])) ? $raw['name'] : $raw['key'];
        $version     = (isset($raw['version']) && is_string($raw['version']) && $raw['version'] !== '') ? $raw['version'] : '0.0.0';
        $nav         = (isset($raw['nav']) && is_string($raw['nav']) && $raw['nav'] !== '') ? $raw['nav'] : null;
        $navDefaults = (isset($raw['nav_defaults']) && is_array($raw['nav_defaults'])) ? $raw['nav_defaults'] : [];
        $teardown    = (isset($raw['teardown']) && is_string($raw['teardown']) && $raw['teardown'] !== '') ? $raw['teardown'] : null;

        // 'resources' names a directory relative to the module class's own
        // directory. A declared directory that does not exist is a packaging
        // mistake: fail loudly rather than run with untranslated pages.
        $resources = null;
        if (isset($raw['resources']) && is_string($raw['resources']) && $raw['resources'] !== '') {
            $rel = $raw['resources'];
            if (str_contains($rel, '..') || str_starts_with($rel, '/') || str_starts_with($rel, '\\')) {
                throw new \LogicException("Module {$class}: 'resources' must be a path inside the module's directory.");
            }
            $base = self::classDir($class);
            $dir  = $base !== null ? $base . DIRECTORY_SEPARATOR . $rel : null;
            if ($dir === null || !is_dir($dir)) {
                throw new \LogicException("Module {$class} declares resources '{$rel}', which is not a directory next to it.");
            }
            $resources = $dir;
        }

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

        $extensions = [];
        if (isset($raw['extensions']) && is_array($raw['extensions'])) {
            foreach ($raw['extensions'] as $interface => $classes) {
                if (!is_string($interface) || $interface === '' || !is_array($classes)) {
                    continue;
                }
                foreach ($classes as $c) {
                    if (is_string($c) && $c !== '') {
                        $extensions[$interface][] = $c;
                    }
                }
            }
        }

        $layout = [];
        if (isset($raw['layout']) && is_array($raw['layout'])) {
            foreach ($raw['layout'] as $slot => $partials) {
                if (!is_string($slot) || $slot === '' || !is_array($partials)) {
                    continue;
                }
                foreach ($partials as $partial) {
                    if (is_string($partial) && $partial !== '') {
                        $layout[$slot][] = $partial;
                    }
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
            'resources'    => $resources,
            'extensions'   => $extensions,
            'layout'       => $layout,
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
