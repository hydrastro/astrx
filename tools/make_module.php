<?php
declare(strict_types=1);

/**
 * AstrX module scaffolder — `php tools/make_module.php <key> [--nav] [--guard]`
 *
 * Generates a new optional-module skeleton so adding one is a single command:
 *   - src/AstrX/<Studly>/module.php          the manifest (discovered by ModuleRegistry)
 *   - src/AstrX/<Studly>/Controller/<Studly>Controller.php  its page controller
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
    // Resource class => policy class, registered with the Gate while enabled.
    'policies'     => [],
    // Page file_name => controller class (resolved by Site\\ControllerMap).
    'controllers'  => [
        '{$key}' => \\AstrX\\{$studly}\\Controller\\{$studly}Controller::class,
    ],
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

use AstrX\\Page\\Page;
use AstrX\\Page\\PageGuard;

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

if (!is_dir("{$moduleDir}/Controller") && !@mkdir("{$moduleDir}/Controller", 0o755, true)) {
    mk_fail("could not create {$moduleDir}/Controller");
}
$emit("{$moduleDir}/Controller/{$studly}Controller.php", <<<PHP
<?php
declare(strict_types=1);

namespace AstrX\\{$studly}\\Controller;

use AstrX\\Kernel\\AbstractController;
use AstrX\\Result\\DiagnosticsCollector;
use AstrX\\Result\\Result;
use AstrX\\Template\\TemplateVars;

/**
 * Serves the '{$key}' page. Mapped in the manifest's `controllers`.
 *
 * Feature controllers depend on Template\\TemplateVars, never on the
 * application's page context, so the module stays packageable on its own
 * (tools/check_architecture.php enforces it).
 */
final class {$studly}Controller extends AbstractController
{
    public function __construct(
        DiagnosticsCollector \$collector,
        private readonly TemplateVars \$ctx,
    ) {
        parent::__construct(\$collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        \$this->ctx->set('{$key}_heading', '{$studly}');
        return \$this->ok();
    }
}

PHP);

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
  4. Add the module's namespace to LAYERS in tools/check_architecture.php
     (usually 'feature').
  5. Verify:  php tools/check_modules.php && php tools/check_architecture.php

TXT);
exit(0);
