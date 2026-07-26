<?php
declare(strict_types=1);

/**
 * AstrX module scaffolder — `php tools/make_module.php <key> [--nav] [--guard]`
 *
 * Generates a new optional-module skeleton so adding one is a single command:
 *   - src/AstrX/<Studly>/module.php          the manifest (discovered by ModuleRegistry)
 *   - src/AstrX/<Studly>/<Studly>NavContributor.php   (only with --nav)
 *   - src/AstrX/<Studly>/<Studly>PageGuard.php         (only with --guard)
 *   - src/setup/modules/<key>.down.sql       teardown stub for `module.php purge`
 * then prints the remaining manual steps (config flag, page tagging, controller).
 *
 * <key> is lowercase [a-z0-9_], the on/off key used in Modules.config.php and the
 * page.module tag; the namespace dir is its StudlyCase form. Never overwrites
 * existing files. See docs/MODULES.md.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

function mk_out(string $s): void { fwrite(STDOUT, $s); }
function mk_fail(string $m): never { fwrite(STDERR, "\nERROR: {$m}\n"); exit(1); }

/** @var list<string> $argv */
$argv    = $argv ?? [];
$key     = '';
$withNav = false;
$withGuard = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--nav') { $withNav = true; }
    elseif ($arg === '--guard') { $withGuard = true; }
    elseif ($arg === '--help' || $arg === '-h') {
        mk_out("Usage: php tools/make_module.php <key> [--nav] [--guard]\n  <key>   lowercase [a-z0-9_]; --nav adds a NavContributor, --guard a PageGuard.\n");
        exit(0);
    }
    elseif ($key === '' && !str_starts_with($arg, '-')) { $key = $arg; }
}

if ($key === '' || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
    mk_fail("give a module key matching [a-z][a-z0-9_]*  (e.g. forum, link_shortener)");
}

$studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));

$root      = dirname(__DIR__);
$moduleDir = $root . "/src/AstrX/{$studly}";
$manifest  = "{$moduleDir}/module.php";
$downFile  = $root . "/src/setup/modules/{$key}.down.sql";

if (is_file($manifest)) {
    mk_fail("module already exists: src/AstrX/{$studly}/module.php");
}
if (!is_dir($moduleDir) && !@mkdir($moduleDir, 0o755, true) && !is_dir($moduleDir)) {
    mk_fail("could not create {$moduleDir}");
}

$navRef   = $withNav   ? "\\AstrX\\{$studly}\\{$studly}NavContributor::class" : 'null';
$guardRef = $withGuard ? "[\\AstrX\\{$studly}\\{$studly}PageGuard::class]"     : '[]';

/** @var list<string> $written Relative paths of the files created. */
$written = [];

/** Write a file only if absent, recording its relative path in $written. */
$emit = static function (string $path, string $body) use ($root, &$written): void {
    if (is_file($path)) { return; }
    if (@file_put_contents($path, $body) === false) { mk_fail("could not write {$path}"); }
    $written[] = substr($path, strlen($root) + 1);
};

$emit($manifest, <<<PHP
<?php
declare(strict_types=1);

/**
 * {$studly} module manifest — discovered by AstrX\\Module\\ModuleRegistry.
 * See docs/MODULES.md for the full contract.
 */
return [
    'key'          => '{$key}',
    'name'         => '{$studly}',
    'version'      => '0.1.0',
    'nav'          => {$navRef},
    'nav_defaults' => [],
    'guards'       => {$guardRef},
    'teardown'     => '{$key}.down.sql',
];

PHP);

if ($withNav) {
    $emit("{$moduleDir}/{$studly}NavContributor.php", <<<PHP
<?php
declare(strict_types=1);

namespace AstrX\\{$studly};

use AstrX\\Module\\NavContributor;

/**
 * Registers {$studly}'s template slots (partial paths / header-footer vars). The
 * matching disabled-defaults go in the manifest's `nav_defaults`. See docs/MODULES.md.
 */
final class {$studly}NavContributor implements NavContributor
{
    /** @return array<string,mixed> */
    public function vars(): array
    {
        // e.g. return ['{$key}_nav' => 'partials/{$key}_nav'];
        return [];
    }
}

PHP);
}

if ($withGuard) {
    $emit("{$moduleDir}/{$studly}PageGuard.php", <<<PHP
<?php
declare(strict_types=1);

namespace AstrX\\{$studly};

use AstrX\\Module\\PageGuard;
use AstrX\\Page\\Page;

/**
 * Optional per-page veto for {$studly} (swap a page for the themed error page).
 * The core ModulePageGuard already 404s this module's pages when it is disabled;
 * only add logic here for finer feature-level gating. See docs/MODULES.md.
 */
final class {$studly}PageGuard implements PageGuard
{
    public function shouldSwapToError(Page \$page): bool
    {
        return false;
    }
}

PHP);
}

$emit($downFile, <<<SQL
-- Teardown for the {$studly} module (tools/module.php purge {$key}).
-- Destructive: removes its pages and drops its tables. Fill in the DROP TABLEs.

DELETE FROM `page` WHERE `module` = '{$key}';

-- SET FOREIGN_KEY_CHECKS = 0;
-- DROP TABLE IF EXISTS `{$key}_example`;
-- SET FOREIGN_KEY_CHECKS = 1;

DELETE ne FROM `navbar_entry` ne
  LEFT JOIN `navbar_internal` ni ON ni.id = ne.id
  LEFT JOIN `navbar_external` nx ON nx.id = ne.id
 WHERE ni.id IS NULL AND nx.id IS NULL;

SQL);

// ── Report ───────────────────────────────────────────────────────────────────
mk_out("Scaffolded module '{$key}' ({$studly}):\n");
foreach ($written as $w) {
    mk_out("  + {$w}\n");
}
mk_out(<<<TXT

Next steps:
  1. Add   '{$key}' => true   to resources/config/Modules.config.php.
  2. Tag its pages so they can be gated — in a migrate_*.sql, e.g.:
         UPDATE `page` SET `module` = '{$key}' WHERE `file_name` LIKE '{$key}%';
  3. Fill in the {$key}.down.sql DROP TABLEs (and the nav/guard stubs if generated).
  4. Verify:  php tools/check_modules.php

TXT);
exit(0);
