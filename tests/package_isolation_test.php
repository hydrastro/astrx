<?php
declare(strict_types=1);

/**
 * Each package loads with only the packages it declares.
 *
 * tools/packages.php checks a package's composer.json against the namespaces
 * its code names. This test checks what PHP itself needs: for every package in
 * packages/, it builds a directory holding that package plus — transitively —
 * the packages its composer.json requires, nothing else, and in a fresh PHP
 * process loads every class the package declares (classes, interfaces, traits,
 * enums, and so every parent and interface they need). A class that extends or
 * implements something from a package that was not declared fails to load.
 *
 * Standalone: no bootstrap, no database.
 */

$root = dirname(__DIR__);
require_once $root . '/tools/lib/sources.php';

$PASS = 0;
$FAIL = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  ok   - {$label}\n";
    } else {
        $FAIL++;
        echo "  FAIL - {$label}" . ($detail !== '' ? "\n         {$detail}" : '') . "\n";
    }
}

function copyTree(string $from, string $to): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    if (!is_dir($to)) { mkdir($to, 0o700, true); }
    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        $dest = $to . substr($item->getPathname(), strlen($from));
        if ($item->isDir()) {
            if (!is_dir($dest)) { mkdir($dest, 0o700, true); }
        } else {
            copy($item->getPathname(), $dest);
        }
    }
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

$packages = AstrX\Tools\packages($root);
check('found the packages (expected 20+)', count($packages) >= 20, (string) count($packages));

/**
 * The package and every repository package it requires, transitively.
 *
 * @param array<string, array{dir: string, json: array<string, mixed>}> $packages
 * @return list<string>
 */
function closure(string $name, array $packages): array
{
    $seen = [];
    $todo = [$name];
    while ($todo !== []) {
        $n = array_pop($todo);
        if (isset($seen[$n]) || !isset($packages[$n])) { continue; }
        $seen[$n] = true;
        foreach (array_keys((array) ($packages[$n]['json']['require'] ?? [])) as $req) {
            $todo[] = (string) $req;
        }
    }
    return array_keys($seen);
}

foreach ($packages as $name => $pkg) {
    $tmp = rtrim(sys_get_temp_dir(), '/') . '/astrx-pkgiso-' . bin2hex(random_bytes(5));
    mkdir($tmp . '/src', 0o700, true);
    copy($root . '/src/classloader.php', $tmp . '/src/classloader.php');

    $map   = '';
    $files = '';
    foreach (closure($name, $packages) as $dep) {
        $dir = $packages[$dep]['dir'];
        copyTree($root . '/' . $dir, $tmp . '/' . $dir);
        $autoload = (array) ($packages[$dep]['json']['autoload'] ?? []);
        foreach ((array) ($autoload['psr-4'] ?? []) as $prefix => $sub) {
            $map .= var_export((string) $prefix, true) . " => [__DIR__ . '/../{$dir}/" . rtrim((string) $sub, '/') . "'],\n";
        }
        foreach ((array) ($autoload['files'] ?? []) as $file) {
            $files .= "require_once __DIR__ . '/{$dir}/{$file}';\n";
        }
    }
    file_put_contents($tmp . '/src/sources.php', "<?php\nreturn [\n{$map}];\n");

    // Every class-like name the package declares, by its PSR-4 path.
    $names = [];
    foreach ((array) (((array) ($pkg['json']['autoload'] ?? []))['psr-4'] ?? []) as $prefix => $sub) {
        $base = rtrim($root . '/' . $pkg['dir'] . '/' . $sub, '/');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $rel = substr($file->getPathname(), strlen($base) + 1);
            if ($file->getExtension() !== 'php' || str_contains('/' . $rel, '/resources/') || basename($rel) === 'constants.php') {
                continue;
            }
            $names[] = $prefix . str_replace('/', '\\', substr($rel, 0, -4));
        }
    }

    $probe = $tmp . '/probe.php';
    file_put_contents($probe, "<?php\nrequire __DIR__ . '/src/classloader.php';\n{$files}"
        . '$missing = [];' . "\n"
        . 'foreach (' . var_export($names, true) . ' as $c) {' . "\n"
        . '    if (!class_exists($c) && !interface_exists($c) && !trait_exists($c) && !enum_exists($c)) { $missing[] = $c; }' . "\n"
        . '}' . "\n"
        . 'echo json_encode($missing);' . "\n");
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
    $missing = json_decode(trim((string) strrchr("\n" . $out, "\n")), true);
    check(
        "{$name}: its " . count($names) . ' classes load with only ' . (count(closure($name, $packages)) - 1) . ' declared package(s)',
        is_array($missing) && $missing === [],
        is_array($missing) ? implode(', ', array_slice($missing, 0, 5)) : substr($out, 0, 400),
    );
    rmTree($tmp);
}

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
