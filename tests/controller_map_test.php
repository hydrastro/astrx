<?php
declare(strict_types=1);

/**
 * Site\ControllerMap — the explicit page → controller map that replaced
 * deriving 'AstrX\Controller\' . Studly(file_name) . 'Controller'.
 *
 * The map must serve exactly the pages the derivation served:
 *
 *   1. every concrete Kernel\Controller in the source tree is reachable through
 *      exactly one map entry (CORE or one module manifest), so no controller
 *      became unreachable when it moved into its feature's namespace;
 *   2. every entry's key is the file_name the old derivation would have needed
 *      for that class (Studly(key) . 'Controller' === short class name), so no
 *      page now reaches a different class;
 *   3. lookups normalise like the derivation did (case- and underscore-
 *      insensitive), so page rows written as 'Board_Mod' keep working;
 *   4. unmapped names and duplicate mappings behave safely.
 *
 * Standalone: no bootstrap, no database.
 */

use AstrX\Kernel\Controller;
use AstrX\Site\ControllerMap;

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

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

// ── The application's entries: CORE + every manifest ───────────────────────
/** @var array<string, string> $entries */
$entries = ControllerMap::CORE;
/** @var array<string, list<string>> $sources class => where it is mapped */
$sources = [];
foreach (ControllerMap::CORE as $fileName => $class) {
    $sources[$class][] = "CORE['{$fileName}']";
}
foreach (AstrX\ApplicationModules::INSTALLED as $module) {
    $manifest = (new $module())->manifest();
    if (!is_array($manifest['controllers'] ?? null)) {
        continue;
    }
    foreach ($manifest['controllers'] as $fileName => $class) {
        $entries[$fileName] = $class;
        $sources[$class][] = "{$module}['{$fileName}']";
    }
}
$map = new ControllerMap($entries);

// ── Every concrete controller class in the tree ─────────────────────────────
/** @var list<class-string> $controllers */
$controllers = [];
require_once $root . '/tools/lib/sources.php';
foreach (AstrX\Tools\classFiles($root) as $class => $path) {
    if (str_ends_with($class, 'Controller') && class_exists($class) && is_subclass_of($class, Controller::class)
        && !(new ReflectionClass($class))->isAbstract()) {
        $controllers[] = $class;
    }
}
sort($controllers);

echo "1. Every controller is reachable exactly once\n";
check('found the controllers (expected 80+)', count($controllers) >= 80, (string) count($controllers));
$unmapped = array_values(array_filter($controllers, static fn(string $c): bool => !isset($sources[$c])));
check('no controller class is unmapped', $unmapped === [], implode(', ', $unmapped));
$dupes = array_filter($sources, static fn(array $where): bool => count($where) > 1);
check('no controller class is mapped twice', $dupes === [], json_encode($dupes, JSON_UNESCAPED_SLASHES) ?: '');
$missing = array_values(array_filter(array_keys($sources), static fn(string $c): bool => !in_array($c, $controllers, true)));
check('every mapped class is a concrete controller', $missing === [], implode(', ', $missing));

echo "\n2. Each key is the file_name the old derivation needed\n";
$mismatch = [];
foreach ($entries as $fileName => $class) {
    $derived = str_replace('_', '', ucwords($fileName, '_')) . 'Controller';
    $short   = substr($class, (int) strrpos($class, '\\') + 1);
    if (strcasecmp($derived, $short) !== 0) {
        $mismatch[] = "'{$fileName}' → {$short} (derivation gave {$derived})";
    }
}
check('Studly(key) . "Controller" === short class name, for every entry', $mismatch === [], implode('; ', $mismatch));

echo "\n3. Lookups normalise like the derivation\n";
check("'board_mod' resolves", $map->classFor('board_mod') === AstrX\Imageboard\Controller\BoardModController::class);
check("'Board_Mod' resolves to the same class", $map->classFor('Board_Mod') === AstrX\Imageboard\Controller\BoardModController::class);
check("'boardmod' resolves to the same class", $map->classFor('boardmod') === AstrX\Imageboard\Controller\BoardModController::class);
check("'admin_config_system' resolves into the Admin feature",
    $map->classFor('admin_config_system') === AstrX\Admin\Controller\AdminConfigSystemController::class);
check("'main' resolves to the site's own controller", $map->classFor('main') === AstrX\Site\Controller\MainController::class);

echo "\n4. Unknown and conflicting names\n";
check('an unmapped file_name has no controller', $map->classFor('no_such_page') === null);
check('a namespace-looking file_name cannot select a class', $map->classFor('..\\Site\\ControllerMap') === null);
$threw = false;
try {
    new ControllerMap(['board_mod' => 'A\\X', 'Board_Mod' => 'B\\Y']);
} catch (LogicException) {
    $threw = true;
}
check('two classes for one normalised key is rejected', $threw);

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
