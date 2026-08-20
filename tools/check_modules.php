<?php
declare(strict_types=1);

use AstrX\Module\NavContributor;
use AstrX\Module\PageGuard;

/**
 * AstrX module integrity check — `php tools/check_modules.php`
 *
 * A zero-dependency, database-free CI gate that validates every optional-module
 * manifest so a broken module can't merge. For each src/AstrX/<Module>/module.php:
 *   - it loads and returns an array with a non-empty `key` (unique) + `version`;
 *   - its `nav` (if any) is a class that implements NavContributor;
 *   - every `guards` entry is a class that implements PageGuard;
 *   - its `teardown` (if named) exists under src/setup/modules/;
 *   - its key is listed in resources/config/Modules.config.php (warning only —
 *     unlisted modules default ON).
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

foreach (glob(CLASS_DIR . '*' . DIRECTORY_SEPARATOR . 'module.php') ?: [] as $file) {
    $rel = substr($file, strlen($root) + 1);
    /** @var mixed $raw */
    $raw = require $file;

    if (!is_array($raw)) {
        $errors[] = "{$rel}: manifest must return an array";
        continue;
    }

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

    // teardown
    $teardownLabel = '—';
    if (isset($raw['teardown']) && is_string($raw['teardown']) && $raw['teardown'] !== '') {
        if (!is_file($modulesDir . $raw['teardown'])) {
            $errors[] = "{$key}: teardown 'src/setup/modules/{$raw['teardown']}' not found";
        } else {
            $teardownLabel = $raw['teardown'];
        }
    }

    // An unlisted module is an ERROR, not a warning. "Unlisted defaults ON" is
    // an unwritten rule: an operator who wants the module off opens
    // Modules.config.php, does not find it there, and has nothing to flip.
    // Seven of eighteen manifests had drifted off the list exactly this way.
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
