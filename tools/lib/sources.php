<?php
declare(strict_types=1);

namespace AstrX\Tools;

/**
 * The AstrX source tree as the tools see it: src/sources.php's PSR-4 map
 * (namespace prefix => directories), turned into the lists the checks walk.
 * Loading this file defines functions only.
 */

/**
 * @return array<string, list<string>> namespace prefix => source directories
 */
function sources(string $root): array
{
    /** @var array<string, list<string>> $map */
    $map = require $root . '/src/sources.php';
    return $map;
}

/**
 * Every source directory, each once.
 *
 * @return list<string>
 */
function sourceDirs(string $root): array
{
    $dirs = [];
    foreach (sources($root) as $list) {
        foreach ($list as $dir) {
            $dirs[$dir] = true;
        }
    }
    return array_keys($dirs);
}

/**
 * Every PHP source file under the source directories, with the name its path
 * implies under PSR-4: path => fully-qualified name. A module's resources/
 * (catalogs, config, SQL) is data and is skipped.
 *
 * @return array<string, string>
 */
function phpFiles(string $root): array
{
    $out = [];
    foreach (sources($root) as $prefix => $dirs) {
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                if (str_contains('/' . $rel, '/resources/')) {
                    continue;
                }
                $out[$file->getPathname()] = $prefix . str_replace('/', '\\', substr($rel, 0, -4));
            }
        }
    }
    ksort($out, SORT_STRING);
    return $out;
}

/**
 * Every PHP class file: fully-qualified name => path. The Support function
 * file (constants.php) holds no class and is left out.
 *
 * @return array<string, string>
 */
function classFiles(string $root): array
{
    $out = [];
    foreach (phpFiles($root) as $path => $name) {
        if (basename($path) !== 'constants.php') {
            $out[$name] = $path;
        }
    }
    ksort($out);
    return $out;
}

/**
 * The repository's own Composer packages: every directory with a
 * composer.json that a path repository of the root composer.json matches.
 *
 * @return array<string, array{dir: string, json: array<string, mixed>}> name => package (dir relative to the root)
 */
function packages(string $root): array
{
    $rootJson = json_decode((string) file_get_contents($root . '/composer.json'), true);
    $out = [];
    foreach ((array) (is_array($rootJson) ? ($rootJson['repositories'] ?? []) : []) as $repo) {
        if (!is_array($repo) || ($repo['type'] ?? '') !== 'path' || !is_string($repo['url'] ?? null)) {
            continue;
        }
        foreach (glob($root . '/' . $repo['url'], GLOB_ONLYDIR) ?: [] as $dir) {
            $json = is_file($dir . '/composer.json')
                ? json_decode((string) file_get_contents($dir . '/composer.json'), true)
                : null;
            if (!is_array($json)) {
                continue;
            }
            $data = [];
            foreach ($json as $key => $value) {
                $data[(string) $key] = $value;
            }
            $name = $data['name'] ?? null;
            if (is_string($name) && !isset($out[$name])) {
                $out[$name] = ['dir' => substr($dir, strlen($root) + 1), 'json' => $data];
            }
        }
    }
    ksort($out);
    return $out;
}

/**
 * The top-level namespaces the module packages (type astrx-module) provide,
 * e.g. 'Chat' for astrx/module-chat.
 *
 * @return list<string>
 */
function moduleNamespaces(string $root): array
{
    $out = [];
    foreach (packages($root) as $pkg) {
        if (($pkg['json']['type'] ?? null) !== 'astrx-module') {
            continue;
        }
        $autoload = $pkg['json']['autoload'] ?? null;
        $psr4 = is_array($autoload) && is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
        foreach (array_keys($psr4) as $prefix) {
            if (preg_match('/^AstrX\\\\(\w+)\\\\$/', (string) $prefix, $m) === 1) {
                $out[] = $m[1];
            }
        }
    }
    sort($out);
    return $out;
}
