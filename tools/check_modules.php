<?php
declare(strict_types=1);

use AstrX\ApplicationModules;
use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\Kernel\Controller;
use AstrX\Module\Module;
use AstrX\Module\NavContributor;
use AstrX\Page\PageGuard;

/**
 * AstrX module integrity check — `php tools/check_modules.php`
 *
 * A zero-dependency, database-free CI gate that validates every optional-module
 * manifest so a broken module can't merge. For each class listed in
 * ApplicationModules::INSTALLED:
 *   - it implements Module\Module and manifest() returns an array with a
 *     non-empty `key` (unique) + `version`;
 *   - its `nav` (if any) is a class that implements NavContributor;
 *   - every `guards` entry is a class that implements PageGuard;
 *   - every `policies` entry maps an existing resource class/interface to a
 *     class that implements PolicyInterface;
 *   - every `permissions` entry is a string-backed enum in the module's own
 *     namespace that implements Auth\Permission;
 *   - every `controllers` entry maps a page file_name to an existing class that
 *     implements Kernel\Controller and lives in the module's own namespace;
 *   - its `teardown` (if named) exists under src/setup/modules/;
 *   - its `resources` (if named) is a directory inside the module's own
 *     directory (templates, catalogs, default config, SQL);
 *   - its key has a boolean flag in resources/config/Modules.config.php (an
 *     installed module without one is OFF, so a missing flag is an error).
 * A Module class present on disk but not installed is reported as a warning.
 *
 * Exit 0 when all manifests are valid (warnings don't fail); exit 1 on any error.
 * Pairs with PHPStan (types) and check_lang_parity.php (i18n) as the third gate.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

$root = dirname(__DIR__);

if (!defined('SRC_DIR'))   { define('SRC_DIR', $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR); }
if (!defined('CLASS_DIR')) { define('CLASS_DIR', SRC_DIR . 'AstrX' . DIRECTORY_SEPARATOR); }

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'AstrX\\', 6) !== 0) {
        return;
    }
    $file = CLASS_DIR . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

/**
 * The installed list as plain strings: the constant is declared
 * list<class-string<Module>>, but checking that promise is this tool's job.
 *
 * @return list<string>
 */
function cm_installed(): array
{
    return ApplicationModules::INSTALLED;
}

/** @var list<string> $errors */
$errors = [];
/** @var list<string> $warnings */
$warnings = [];
/** @var list<array{key:string,version:string,nav:string,guards:int,teardown:string}> $rows */
$rows = [];
/** @var array<string,string> $seen */
$seen = [];

// Configured module flags. $configuredRaw keeps the value AS WRITTEN so a
// non-boolean can be reported: casting first is what hid `'chat' => 'false'`
// (a truthy string) behind a perfectly innocent-looking `true`.
/** @var array<string,bool> $configured */
$configured = [];
/** @var array<string,mixed> $configuredRaw */
$configuredRaw = [];
$configFile = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'Modules.config.php';
if (is_file($configFile)) {
    /** @var mixed $c */
    $c = require $configFile;
    if (is_array($c) && isset($c['Modules']) && is_array($c['Modules'])) {
        /** @var mixed $v */
        foreach ($c['Modules'] as $k => $v) {
            if (is_string($k)) { $configured[$k] = (bool) $v; $configuredRaw[$k] = $v; }
        }
    }
}

$modulesDir = SRC_DIR . 'setup' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR;

// Present but not installed: a Module class on disk that the application
// does not list is inert — worth a warning, since it is usually a forgotten
// ApplicationModules entry.
foreach (glob(CLASS_DIR . '*' . DIRECTORY_SEPARATOR . '*Module.php') ?: [] as $file) {
    $class = 'AstrX\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
    if (class_exists($class) && is_subclass_of($class, Module::class)
        && !in_array($class, cm_installed(), true)) {
        $warnings[] = "{$class} is present but not listed in ApplicationModules::INSTALLED (inert)";
    }
}

foreach (cm_installed() as $class) {
    $rel = $class;
    if (!class_exists($class) || !is_subclass_of($class, Module::class)) {
        $errors[] = "{$rel}: listed in ApplicationModules::INSTALLED but not a class implementing " . Module::class;
        continue;
    }
    // Declarations must live in the module's own namespace.
    $moduleNs = substr($class, 0, (int) strrpos($class, '\\') + 1);
    $raw = (new $class())->manifest();

    $key = (isset($raw['key']) && is_string($raw['key'])) ? $raw['key'] : '';
    if ($key === '') {
        $errors[] = "{$rel}: missing or empty 'key'";
        continue;
    }
    if (isset($seen[$key])) {
        $errors[] = "{$rel}: duplicate key '{$key}' (also declared in {$seen[$key]})";
        continue;
    }
    $seen[$key] = $rel;

    if (!isset($raw['name']) || !is_string($raw['name']) || $raw['name'] === '') {
        $warnings[] = "{$key}: missing 'name'";
    }

    $version = (isset($raw['version']) && is_string($raw['version'])) ? $raw['version'] : '';
    if ($version === '') {
        $errors[] = "{$key}: missing 'version'";
    }

    // nav contributor (isset() is false for the null default, so it's skipped)
    $navLabel = '—';
    if (isset($raw['nav'])) {
        $nav = $raw['nav'];
        if (!is_string($nav) || !class_exists($nav)) {
            $errors[] = "{$key}: nav class " . (is_string($nav) ? "'{$nav}'" : gettype($nav)) . ' not found';
        } elseif (!is_subclass_of($nav, NavContributor::class)) {
            $errors[] = "{$key}: nav '{$nav}' does not implement " . NavContributor::class;
        } else {
            $navLabel = substr((string) strrchr($nav, '\\'), 1);
        }
    }

    // guards
    $guardCount = 0;
    if (isset($raw['guards']) && is_array($raw['guards'])) {
        /** @var mixed $g */
        foreach ($raw['guards'] as $g) {
            $guardCount++;
            if (!is_string($g) || !class_exists($g)) {
                $errors[] = "{$key}: guard " . (is_string($g) ? "'{$g}'" : gettype($g)) . ' not found';
            } elseif (!is_subclass_of($g, PageGuard::class)) {
                $errors[] = "{$key}: guard '{$g}' does not implement " . PageGuard::class;
            }
        }
    }

    // policies: resource class => policy class
    if (isset($raw['policies'])) {
        if (!is_array($raw['policies'])) {
            $errors[] = "{$key}: 'policies' must be an array of resource class => policy class";
        } else {
            /** @var mixed $policy */
            foreach ($raw['policies'] as $resource => $policy) {
                if (!is_string($resource) || !(class_exists($resource) || interface_exists($resource))) {
                    $errors[] = "{$key}: policy resource " . (is_string($resource) ? "'{$resource}'" : gettype($resource)) . ' not found';
                }
                if (!is_string($policy) || !class_exists($policy)) {
                    $errors[] = "{$key}: policy " . (is_string($policy) ? "'{$policy}'" : gettype($policy)) . ' not found';
                } elseif (!is_subclass_of($policy, PolicyInterface::class)) {
                    $errors[] = "{$key}: policy '{$policy}' does not implement " . PolicyInterface::class;
                }
            }
        }
    }

    // permissions: enum classes owned by this module
    if (isset($raw['permissions'])) {
        if (!is_array($raw['permissions'])) {
            $errors[] = "{$key}: 'permissions' must be a list of permission enum classes";
        } else {
            /** @var mixed $enum */
            foreach ($raw['permissions'] as $enum) {
                if (!is_string($enum) || !enum_exists($enum)) {
                    $errors[] = "{$key}: permission enum " . (is_string($enum) ? "'{$enum}'" : gettype($enum)) . ' not found';
                } elseif (!is_subclass_of($enum, Permission::class)) {
                    $errors[] = "{$key}: '{$enum}' does not implement " . Permission::class;
                } elseif (!str_starts_with($enum, $moduleNs)) {
                    $errors[] = "{$key}: permission enum '{$enum}' is outside the module namespace {$moduleNs}";
                }
            }
        }
    }

    // controllers: page file_name => controller class, owned by this module
    if (isset($raw['controllers'])) {
        if (!is_array($raw['controllers'])) {
            $errors[] = "{$key}: 'controllers' must be an array of page file_name => controller class";
        } else {
            /** @var mixed $ctrl */
            foreach ($raw['controllers'] as $fileName => $ctrl) {
                if (!is_string($fileName) || $fileName === '') {
                    $errors[] = "{$key}: controller key must be a page file_name";
                }
                if (!is_string($ctrl) || !class_exists($ctrl)) {
                    $errors[] = "{$key}: controller " . (is_string($ctrl) ? "'{$ctrl}'" : gettype($ctrl)) . ' not found';
                } elseif (!is_subclass_of($ctrl, Controller::class)) {
                    $errors[] = "{$key}: controller '{$ctrl}' does not implement " . Controller::class;
                } elseif (!str_starts_with($ctrl, $moduleNs)) {
                    $errors[] = "{$key}: controller '{$ctrl}' is outside the module namespace {$moduleNs}";
                }
            }
        }
    }

    // teardown
    $teardownLabel = '—';
    if (isset($raw['teardown']) && is_string($raw['teardown']) && $raw['teardown'] !== '') {
        if (!is_file($modulesDir . $raw['teardown'])) {
            $errors[] = "{$key}: teardown 'src/setup/modules/{$raw['teardown']}' not found";
        } else {
            $teardownLabel = $raw['teardown'];
        }
    }

    // resources: a directory next to the module class
    if (isset($raw['resources'])) {
        $res = $raw['resources'];
        $dir = dirname((string) (new ReflectionClass($class))->getFileName());
        if (!is_string($res) || $res === '' || str_contains($res, '..') || str_starts_with($res, '/')) {
            $errors[] = "{$key}: 'resources' must be a relative directory inside the module";
        } elseif (!is_dir($dir . DIRECTORY_SEPARATOR . $res)) {
            $errors[] = "{$key}: resources directory '{$res}' not found next to " . basename((string) (new ReflectionClass($class))->getFileName());
        }
    }

    // An installed module without a flag is an ERROR, not a warning: it is OFF
    // at runtime (ModuleRegistry's default), which is safe but invisible — the
    // operator opens Modules.config.php and finds nothing to flip. Seven of
    // eighteen manifests once drifted off the list exactly this way.
    if (!array_key_exists($key, $configured)) {
        $errors[] = "{$key}: not listed in Modules.config.php — add \"'{$key}' => true,\" "
            . 'to the Modules section so the module can be switched off';
    } elseif (!is_bool($configuredRaw[$key])) {
        // Config::getConfigBool now rejects a non-boolean and falls back to ON
        // instead of casting it truthy, so a quoted 'false' no longer reads as
        // "enabled" — but it still does not mean what its author intended.
        $errors[] = "{$key}: Modules.config.php holds a " . get_debug_type($configuredRaw[$key])
            . ', not a boolean — write true or false';
    }

    $rows[] = [
        'key'      => $key,
        'version'  => $version !== '' ? $version : '?',
        'nav'      => $navLabel,
        'guards'   => $guardCount,
        'teardown' => $teardownLabel,
    ];
}

// ── Report ───────────────────────────────────────────────────────────────────
fwrite(STDOUT, "AstrX module integrity\n======================\n\n");
usort($rows, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));
fwrite(STDOUT, sprintf("  %-14s %-8s %-26s %-7s %s\n", 'MODULE', 'VERSION', 'NAV', 'GUARDS', 'TEARDOWN'));
foreach ($rows as $r) {
    fwrite(STDOUT, sprintf("  %-14s %-8s %-26s %-7d %s\n", $r['key'], $r['version'], $r['nav'], $r['guards'], $r['teardown']));
}
fwrite(STDOUT, "\n");

foreach ($warnings as $w) {
    fwrite(STDOUT, "  warning: {$w}\n");
}
foreach ($errors as $e) {
    fwrite(STDERR, "  ERROR: {$e}\n");
}

if ($errors !== []) {
    fwrite(STDERR, "\n" . count($errors) . " error(s) — module integrity check FAILED.\n");
    exit(1);
}
fwrite(STDOUT, count($rows) . " module(s) OK" . ($warnings !== [] ? ' (' . count($warnings) . " warning(s))" : '') . ".\n");
exit(0);
