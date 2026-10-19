<?php
declare(strict_types=1);

/**
 * AstrX package check — `php tools/packages.php [--write]`
 *
 * The code lives in Composer packages inside this repository (packages/*,
 * packages/modules/*), found through the root composer.json's path
 * repositories — or, in an application that gets them from Composer instead,
 * the astrx/* packages installed under vendor/. This tool keeps the pieces
 * that describe them in agreement:
 *
 *   - every package has a name, a type and a PSR-4 map whose directories
 *     exist, and no namespace is declared by two packages;
 *   - the root package requires every package;
 *   - each package requires exactly the packages whose namespaces its code
 *     uses (from tools/check_architecture.php --graph), so it can be installed
 *     on its own;
 *   - src/sources.php — the map the application's own class loader and the
 *     tools read — is the union of the packages' PSR-4 maps, and
 *     src/support.php loads the union of their `files` (functions, which
 *     cannot be autoloaded). Each package is named at both places it can be,
 *     packages/<dir> and vendor/<name>, and whichever exists is used, so the
 *     same generated files serve this repository and an application that
 *     installs the packages with Composer;
 *   - src/resources.php lists the resource directories of the packages that
 *     are not modules (composer.json extra.astrx.resources: templates,
 *     catalogs, default config), the lowest-level package first. Modules
 *     declare theirs in their manifest instead.
 *
 * --write regenerates src/sources.php, src/support.php and src/resources.php
 * instead of checking them. --installed generates (or checks) them as an
 * application that installs the packages with Composer has them, naming
 * vendor/<name> only — what the site's own repository holds once the
 * packages live elsewhere (tools/split.php).
 * Exit 0 when everything holds; exit 1 otherwise. Database-free, zero-dependency.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

$root  = dirname(__DIR__);
$write = in_array('--write', $argv, true);
// Generate for the layout of an application that installs the packages with
// Composer: vendor/<name> only (tools/split.php uses it for the site).
$installedLayout = in_array('--installed', $argv, true);
// --write-to=DIR: write the generated files under DIR/src/ instead of here.
$writeTo = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--write-to=')) {
        $writeTo = rtrim(substr($arg, 11), '/');
        $write   = true;
    }
}
require_once __DIR__ . '/lib/sources.php';

/** @return array<string, mixed> */
function pk_json(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        fwrite(STDERR, "ERROR: {$file} is not valid JSON\n");
        exit(1);
    }
    /** @var array<string, mixed> $data */
    return $data;
}

/**
 * @param array<string, mixed> $composer
 * @return list<string> files relative to the package
 */
function pk_files(array $composer): array
{
    $autoload = $composer['autoload'] ?? null;
    $files = is_array($autoload) ? ($autoload['files'] ?? null) : null;
    return is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
}

/**
 * @param array<string, mixed> $composer
 * @return array<string, string> prefix => directory relative to the package
 */
function pk_psr4(array $composer): array
{
    $autoload = $composer['autoload'] ?? null;
    $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
    $out = [];
    if (is_array($psr4)) {
        foreach ($psr4 as $prefix => $dir) {
            if (is_string($prefix) && is_string($dir)) {
                $out[$prefix] = $dir;
            }
        }
    }
    return $out;
}

$errors = [];
$rootJson = pk_json($root . '/composer.json');

// ── The packages: every directory a path repository matches ─────────────────
/** @var array<string, array{dir: string, json: array<string, mixed>}> $packages name => package */
$packages = [];
foreach ((array) ($rootJson['repositories'] ?? []) as $repo) {
    if (!is_array($repo) || ($repo['type'] ?? '') !== 'path' || !is_string($repo['url'] ?? null)) {
        continue;
    }
    foreach (glob($root . '/' . $repo['url'], GLOB_ONLYDIR) ?: [] as $dir) {
        if (!is_file($dir . '/composer.json')) {
            continue;
        }
        $json = pk_json($dir . '/composer.json');
        $name = is_string($json['name'] ?? null) ? $json['name'] : '';
        $rel  = substr($dir, strlen($root) + 1);
        if ($name === '') {
            $errors[] = "{$rel}/composer.json has no name";
            continue;
        }
        if (isset($packages[$name])) {
            $errors[] = "{$name} is declared twice: {$packages[$name]['dir']} and {$rel}";
            continue;
        }
        $packages[$name] = ['dir' => $rel, 'json' => $json];
    }
}
ksort($packages);
if ($packages === []) {
    // No package in this repository: an application that installs them with
    // Composer. Take the ones it installed.
    $packages = AstrX\Tools\installedPackages($root);
}
if ($packages === []) {
    $errors[] = 'no package found: the root composer.json names no path repository with a package in it, '
        . 'and vendor/ holds no installed astrx/* package (run composer install)';
}

// ── Namespaces: prefix => [package, directory]; function files ──────────────
/** @var array<string, array{0: string, 1: string}> $owner */
$owner = [];
/** @var list<array{0: string, 1: string}> $functionFiles [path relative to the root, package] */
$functionFiles = [];
foreach (pk_files($rootJson) as $file) {
    $functionFiles[] = [$file, '(root)'];
}
foreach (pk_psr4($rootJson) as $prefix => $dir) {
    $owner[$prefix] = ['(root)', rtrim($dir, '/')];
}
foreach ($packages as $name => $pkg) {
    if (!is_string($pkg['json']['type'] ?? null)) {
        $errors[] = "{$name}: no type";
    }
    foreach (pk_files($pkg['json']) as $file) {
        if (!is_file($root . '/' . $pkg['dir'] . '/' . $file)) {
            $errors[] = "{$name}: autoloaded file {$file} does not exist";
        }
        $functionFiles[] = [$pkg['dir'] . '/' . $file, $name];
    }
    $map = pk_psr4($pkg['json']);
    if ($map === []) {
        $errors[] = "{$name}: no PSR-4 autoload map";
    }
    foreach ($map as $prefix => $dir) {
        $path = rtrim($pkg['dir'] . '/' . $dir, '/');
        if (!is_dir($root . '/' . $path)) {
            $errors[] = "{$name}: {$prefix} maps to {$path}, which does not exist";
        }
        if (isset($owner[$prefix])) {
            $errors[] = "{$prefix} is declared by both {$owner[$prefix][0]} and {$name}";
            continue;
        }
        $owner[$prefix] = [$name, $path];
    }
}
ksort($owner);

// ── The root requires every package ──────────────────────────────────────────
$rootRequire = (array) ($rootJson['require'] ?? []);
foreach (array_keys($packages) as $name) {
    if (!isset($rootRequire[$name])) {
        $errors[] = "the root composer.json does not require {$name}";
    }
}

// ── Each package requires the packages its code uses, and no others ─────────
/** The package owning a top-level node of the architecture graph. */
$packageOf = static function (string $node) use ($owner): string {
    $best = '';
    $pkg  = '(root)';
    foreach ($owner as $prefix => [$name]) {
        $candidate = 'AstrX\\' . $node . '\\';
        if (str_starts_with($candidate, $prefix) && strlen($prefix) > strlen($best)) {
            $best = $prefix;
            $pkg  = $name;
        }
    }
    return $pkg;
};
$graph = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/check_architecture.php') . ' --graph');
/** @var array<string, array<string, true>> $uses package => package => true */
$uses = [];
foreach (explode("\n", (string) $graph) as $line) {
    if (preg_match('/^(\w+)\s+->\s+(\w+)\s+\d+$/', trim($line), $m) !== 1) {
        continue;
    }
    [$from, $to] = [$packageOf($m[1]), $packageOf($m[2])];
    if ($from !== $to) {
        $uses[$from][$to] = true;
    }
}
if ($uses === []) {
    $errors[] = 'tools/check_architecture.php --graph reported no dependencies';
}
foreach ($packages as $name => $pkg) {
    $required = array_filter(
        array_keys((array) ($pkg['json']['require'] ?? [])),
        static fn(int|string $r): bool => isset($packages[(string) $r]),
    );
    $needed = array_keys($uses[$name] ?? []);
    foreach (array_diff($needed, $required) as $missing) {
        $errors[] = $missing === '(root)'
            ? "{$name} uses the application (src/AstrX), which no package may"
            : "{$name} uses {$missing} but does not require it";
    }
    foreach (array_diff($required, $needed) as $unused) {
        $errors[] = "{$name} requires {$unused} but does not use it";
    }
}

// ── src/sources.php: the union of the PSR-4 maps ─────────────────────────────
/**
 * Every place a file of a package may be, relative to src/: the package's
 * own directory (packages/… here, vendor/… when installed), then where
 * Composer installs it. The application's own files (src/…) are only here.
 *
 * @return list<string>
 */
$candidates = static function (string $path, string $package) use ($packages, $installedLayout): array {
    if ($package === '(root)') {
        return [str_starts_with($path, 'src/') ? substr($path, 4) : '../' . $path];
    }
    $dir = $packages[$package]['dir'];
    $sub = substr($path, strlen($dir));        // '' or '/Sub/Dir'
    $installed = 'vendor/' . $package . $sub;
    $out = $installedLayout || $installed === $path ? [] : ['../' . $path];
    $out[] = '../' . $installed;
    return $out;
};
/**
 * PHP source: a list of __DIR__-relative paths.
 *
 * @param list<string> $rels
 */
function pk_export_list(array $rels): string
{
    $parts = [];
    foreach ($rels as $rel) {
        $parts[] = '__DIR__ . ' . var_export('/' . $rel, true);
    }
    return implode(', ', $parts);
}
$exportList = pk_export_list(...);

$lines = [];
foreach ($owner as $prefix => [$pkgName, $path]) {
    $lines[] = '    ' . var_export($prefix, true) . ' => [' . $exportList($candidates($path, $pkgName)) . '],';
}
$sources = <<<PHP
<?php
declare(strict_types=1);

/**
 * Where the AstrX classes live: PSR-4 namespace prefix => source directories,
 * the union of the composer.json maps of the application and its packages.
 * src/classloader.php loads classes from it, and the tools that scan the
 * source (architecture, config and module checks, the compiled build) walk it.
 *
 * A package is listed at each place it can be — in this repository
 * (packages/…) and installed by Composer (vendor/astrx/…); the loader uses
 * the first that has the file.
 *
 * Generated by `php tools/packages.php --write`; do not edit by hand.
 *
 * @return array<string, list<string>>
 */
return [

PHP . implode("\n", $lines) . "\n];\n";

$requires = [];
foreach ($functionFiles as [$file, $pkgName]) {
    $requires[] = 'require_once \\AstrX\\Bootstrap\\firstFile(' . $exportList($candidates($file, $pkgName)) . ');';
}
$support = <<<PHP
<?php
declare(strict_types=1);

// The packages' function files (composer.json `files`: functions cannot be
// autoloaded), loaded eagerly: the AstrX\\Support helpers. Each is named at
// every place its package can be (see src/sources.php); the first that
// exists is loaded.
//
// Generated by `php tools/packages.php --write`; do not edit by hand.

namespace AstrX\\Bootstrap;

if (!function_exists(__NAMESPACE__ . '\\firstFile')) {
    /** The first of \$paths that exists, or the first one (so require reports it). */
    function firstFile(string ...\$paths): string
    {
        foreach (\$paths as \$path) {
            if (file_exists(\$path)) {
                return \$path;
            }
        }
        return \$paths[0];
    }
}


PHP . implode("\n", $requires) . "\n";

// ── src/resources.php: the core packages' resource directories ──────────────
// Lowest level first (a package after every package it requires), so a
// package's catalogs and config can override those of the packages below it.
$ordered = [];
$visit = static function (string $name) use (&$visit, &$ordered, $packages): void {
    if (isset($ordered[$name]) || !isset($packages[$name])) {
        return;
    }
    $ordered[$name] = false;     // in progress: the architecture gate rules out cycles
    $requires = array_keys((array) ($packages[$name]['json']['require'] ?? []));
    sort($requires);
    foreach ($requires as $req) {
        $visit((string) $req);
    }
    unset($ordered[$name]);
    $ordered[$name] = true;
};
foreach (array_keys($packages) as $name) {
    $visit($name);
}
$resourceLines = [];
foreach (array_keys($ordered) as $name) {
    $json = $packages[$name]['json'];
    $extra = is_array($json['extra'] ?? null) ? $json['extra'] : [];
    $astrx = is_array($extra['astrx'] ?? null) ? $extra['astrx'] : [];
    $res   = $astrx['resources'] ?? null;
    if ($res === null) {
        continue;
    }
    $dir = $packages[$name]['dir'] . '/' . (is_string($res) ? trim($res, '/') : '');
    if (!is_string($res) || $res === '' || str_contains($res, '..') || !is_dir($root . '/' . $dir)) {
        $errors[] = "{$name}: extra.astrx.resources must name a directory inside the package";
        continue;
    }
    if (($json['type'] ?? null) === 'astrx-module') {
        $errors[] = "{$name}: a module declares its resources in its manifest, not in composer.json";
        continue;
    }
    $resourceLines[] = '    [' . $exportList($candidates($dir, $name)) . '], // ' . $name;
}
$resources = <<<PHP
<?php
declare(strict_types=1);

/**
 * The resource directories of the packages that are not modules (templates,
 * catalogs, default config), lowest-level package first. The application
 * layers them under the installed modules' and the site's own resources/
 * (ApplicationContainer::resourceRoots()). Each entry lists every place the
 * package can be (see src/sources.php); the first that exists is used.
 *
 * Generated by `php tools/packages.php --write` from each package's
 * composer.json extra.astrx.resources; do not edit by hand.
 *
 * @return list<list<string>>
 */
return [

PHP . implode("\n", $resourceLines) . ($resourceLines === [] ? '' : "\n") . "];\n";

$outRoot = $writeTo ?? $root;
$generated = [
    $outRoot . '/src/sources.php'   => $sources,
    $outRoot . '/src/support.php'   => $support,
    $outRoot . '/src/resources.php' => $resources,
];
foreach ($generated as $file => $content) {
    $rel = substr($file, strlen($outRoot) + 1);
    if ($write) {
        file_put_contents($file, $content);
        fwrite(STDOUT, "wrote {$rel}\n");
    } elseif ((string) @file_get_contents($file) !== $content) {
        $errors[] = "{$rel} is out of date: run php tools/packages.php --write";
    }
}

// ── Report ───────────────────────────────────────────────────────────────────
fwrite(STDOUT, "AstrX packages\n==============\n\n");
foreach ($packages as $name => $pkg) {
    $deps = array_keys($uses[$name] ?? []);
    sort($deps);
    fwrite(STDOUT, sprintf("  %-26s %-31s %s\n", $name, $pkg['dir'], $deps === [] ? '—' : implode(', ', $deps)));
}
fwrite(STDOUT, "\n");
if ($errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDOUT, "  ERROR: {$e}\n");
    }
    fwrite(STDOUT, "\n" . count($errors) . " error(s) — package check FAILED.\n");
    exit(1);
}
fwrite(STDOUT, count($packages) . " package(s) OK.\n");
exit(0);
