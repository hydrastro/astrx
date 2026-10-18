<?php
declare(strict_types=1);

namespace AstrX\Config;

use AstrX\Support\ResourceRoots;
use ReflectionClass;
use function AstrX\Support\configDir;

/**
 * The single authority on "which config section does this class use, and which
 * file declares that section".
 *
 * Two independent sources, in priority order:
 *
 *   1. `#[ConfigDomain]` on the class — an explicit declaration.
 *   2. The historical convention — class short name, plus the immediate parent
 *      namespace segment when no `{ShortName}.config.php` exists. Kept so every
 *      unannotated class behaves exactly as before.
 *
 * {@see fileForSection()} answers the writer's question by scanning the config
 * directories for the file that actually declares a section. That scan is the
 * ground truth: it reads the same files {@see Config::loadModuleConfig()} reads
 * (the site's directory, then each installed module's config/ defaults), so
 * reader and writer cannot disagree about where a section lives.
 */
final class ConfigDomainResolver
{
    /**
     * section name => config file base name, built lazily from the on-disk
     * layout. Null until the first scan.
     *
     * @var array<string,string>|null
     */
    private ?array $layout = null;

    /**
     * @param string $configDir Config directory override; empty uses CONFIG_DIR.
     *                          Injected in tests so the scan does not depend on
     *                          global constants.
     * @param ResourceRoots|null $roots the installed modules' resource
     *                          directories, whose config/ holds defaults.
     */
    public function __construct(private string $configDir = '', ?ResourceRoots $roots = null)
    {
        if ($this->configDir === '') {
            $this->configDir = configDir();
        }
        $this->roots = $roots ?? new ResourceRoots();
    }

    private readonly ResourceRoots $roots;

    /**
     * Every directory a config file may come from: the site's first (it names
     * the file when both declare a section), then the modules' defaults.
     *
     * @return list<string>
     */
    private function dirs(): array
    {
        return [$this->configDir(), ...$this->roots->of(ResourceRoots::CONFIG)];
    }

    /** The same resolver over a different site config directory (same module defaults). */
    public function withConfigDir(string $configDir): self
    {
        return new self($configDir, $this->roots);
    }

    /** Whether {$fileBaseName}.config.php exists in the site's or a module's config directory. */
    public function hasFile(string $fileBaseName): bool
    {
        foreach ($this->dirs() as $dir) {
            if (is_file($dir . $fileBaseName . '.config.php')) {
                return true;
            }
        }
        return false;
    }

    /** The config directory this resolver scans, with a trailing separator. */
    public function configDir(): string
    {
        return rtrim($this->configDir, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * The (section, file) pairs a class draws its config from, in application
     * order. Never empty: an unannotated class always yields at least its own
     * short name.
     *
     * @return list<array{section:string,file:string}>
     */
    public function forClass(string $fqcn): array
    {
        $declared = self::declaredOn($fqcn);
        if ($declared !== []) {
            $out = [];
            foreach ($declared as $domain) {
                $out[] = ['section' => $domain->section, 'file' => $domain->fileBaseName()];
            }
            return $out;
        }

        return $this->byConvention($fqcn);
    }

    /**
     * The `#[ConfigDomain]` attributes on a class, or [] when it has none.
     *
     * @return list<ConfigDomain>
     */
    public static function declaredOn(string $fqcn): array
    {
        // #[ConfigDomain] is TARGET_CLASS, so a name that is not a loadable
        // class carries none. Checking first keeps ReflectionClass from
        // throwing on a name the autoloader cannot resolve.
        if (!class_exists($fqcn)) {
            return [];
        }
        $rc = new ReflectionClass($fqcn);

        $out = [];
        foreach ($rc->getAttributes(ConfigDomain::class) as $attr) {
            $out[] = $attr->newInstance();
        }
        return $out;
    }

    /**
     * The config file base name that declares $section, or null when no file in
     * the config directory does.
     *
     * Used by {@see ConfigWriter} to route a section to the file the loader will
     * actually read, instead of trusting a hand-written file name.
     */
    public function fileForSection(string $section): ?string
    {
        return $this->layout()[$section] ?? null;
    }

    /**
     * Every section currently declared on disk, mapped to its file base name.
     *
     * @return array<string,string>
     */
    public function layout(): array
    {
        if ($this->layout !== null) {
            return $this->layout;
        }

        $map = [];
        $files = [];
        foreach ($this->dirs() as $dir) {
            foreach (self::configFiles($dir) as $base => $path) {
                $files[] = [$base, $path];
            }
        }
        foreach ($files as [$base, $path]) {
            /** @var mixed $loaded */
            $loaded = @include $path;
            if (!is_array($loaded)) {
                continue;
            }
            /** @var mixed $_ */
            foreach ($loaded as $section => $_) {
                if (is_string($section) && !isset($map[$section])) {
                    $map[$section] = $base;
                }
            }
        }

        $this->layout = $map;
        return $map;
    }

    /**
     * Every config file in $dir, keyed by base name ('Mail' => '/…/Mail.config.php').
     * `config.php` (the main file, base name 'config') is included: it declares
     * the Prelude/ModuleLoader/ErrorHandler/Injector sections.
     *
     * @return array<string,string>
     */
    public static function configFiles(string $dir): array
    {
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.config.php') ?: [] as $path) {
            $out[basename($path, '.config.php')] = $path;
        }

        $main = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'config.php';
        if (is_file($main)) {
            $out['config'] = $main;
        }

        return $out;
    }

    /**
     * The pre-attribute convention, preserved verbatim so unannotated classes
     * keep their current behaviour.
     *
     * @return list<array{section:string,file:string}>
     */
    private function byConvention(string $fqcn): array
    {
        $parts = explode('\\', $fqcn);
        $short = $parts[count($parts) - 1];

        $out = [['section' => $short, 'file' => $short]];

        // Only fall back to the parent namespace when the class has no config
        // file of its own — matching ModuleLoader's original resolution order.
        if ($this->hasFile($short)) {
            return $out;
        }

        $count = count($parts);
        if ($count >= 3) {
            $parent = $parts[$count - 2];
            if ($parent !== $short) {
                $out[] = ['section' => $parent, 'file' => $parent];
            }
        }

        return $out;
    }
}
