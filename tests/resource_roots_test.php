<?php
declare(strict_types=1);

/**
 * Module resources: templates, catalogs and config a module ships in its own
 * directory, layered under the site's resources/.
 *
 *   1. ModuleRegistry resolves a manifest's `resources` next to the module
 *      class and rejects one that escapes it or does not exist.
 *   2. Templates: the site's file wins; otherwise the module's; a symlink out
 *      of a module's template/ is refused.
 *   3. Catalogs: module keys load, the site's keys override them key by key;
 *      DiagnosticRenderer does the same for Diagnostics/.
 *   4. Config: a module's file is the default, the site's file overrides it
 *      key by key, and the section is routed to that file name for writes.
 *   5. The language editor shows the merged catalog, writes only the site's
 *      overrides, and a new language gets the module's strings too.
 *
 * Standalone: no bootstrap, no database.
 */

use AstrX\Config\Config;
use AstrX\Config\ConfigDomainResolver;
use AstrX\I18n\DiagnosticRenderer;
use AstrX\I18n\LangCatalog;
use AstrX\I18n\Translator;
use AstrX\Module\ModuleRegistry;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\AbstractDiagnostic;
use AstrX\Support\ResourceRoots;
use AstrX\Template\TemplateEngine;

$root = dirname(__DIR__);
$tmp  = rtrim(sys_get_temp_dir(), '/') . '/astrx-resroots-' . bin2hex(random_bytes(5));
$site = $tmp . '/site';
mkdir($site . '/config', 0o700, true);
mkdir($site . '/lang/en', 0o700, true);
mkdir($site . '/template', 0o700, true);
require $root . '/src/autoload.php';
// The site under test, handed to what needs it (no path constant is defined).
$paths = new AstrX\Support\Paths(config: $site . '/config/', lang: $site . '/lang/', templates: $site . '/template/');

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

function put(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0o700, true);
    }
    file_put_contents($path, $contents);
}

/** A module class in its own directory, optionally with a resources/ dir. */
function fakeModule(string $base, string $name, ?string $resources): string
{
    $ns  = 'ResRootsTest' . $name;
    $dir = $base . '/' . $name;
    put($dir . "/{$name}Module.php", "<?php\nnamespace {$ns};\nfinal class {$name}Module implements \\AstrX\\Module\\Module {\n"
        . "    public function manifest(): array { return ['key' => '" . strtolower($name) . "', 'version' => '1.0.0'"
        . ($resources !== null ? ", 'resources' => " . var_export($resources, true) : '') . "]; }\n}\n");
    require $dir . "/{$name}Module.php";
    return "{$ns}\\{$name}Module";
}

function threw(callable $fn): bool
{
    try { $fn(); } catch (LogicException) { return true; }
    return false;
}

try {
    echo "1. Where a module's resources are\n";
    $mods = $tmp . '/modules';
    $alpha = fakeModule($mods, 'Alpha', 'resources');
    mkdir($mods . '/Alpha/resources/template', 0o700, true);
    $beta  = fakeModule($mods, 'Beta', null);
    $roots = ModuleRegistry::resourceRootsFor([$alpha, $beta]);
    check('a declared resources/ resolves next to the module class',
        $roots->dirs() === [$mods . '/Alpha/resources'], json_encode($roots->dirs()) ?: '');
    check('a module without one contributes nothing', count($roots->dirs()) === 1);
    check('of() lists only the kinds that exist',
        $roots->of(ResourceRoots::TEMPLATE) === [$mods . '/Alpha/resources/template/'] && $roots->of(ResourceRoots::LANG) === []);
    $escape  = fakeModule($mods, 'Gamma', '../Alpha/resources');
    $missing = fakeModule($mods, 'Delta', 'nope');
    check("'resources' may not leave the module's directory", threw(static fn() => ModuleRegistry::resourceRootsFor([$escape])));
    check('a declared directory that does not exist fails loudly', threw(static fn() => ModuleRegistry::resourceRootsFor([$missing])));

    $mres = $mods . '/Alpha/resources';
    $roots = new ResourceRoots([$mres]);

    echo "\n2. Templates\n";
    put($mres . '/template/alpha.html', 'module alpha');
    put($mres . '/template/admin/admin_alpha.html', 'module admin');
    put($mres . '/template/shared.html', 'module shared');
    put($site . '/template/shared.html', 'site shared');
    $engine = new TemplateEngine(new DiagnosticsCollector(), $roots, $paths);
    $engine->setCacheTemplates(false);
    $render = static function (string $name) use ($engine): string {
        $r = $engine->renderTemplate($name);
        return $r->isOk() ? (string) $r->unwrap() : '(failed)';
    };
    check('a template only the module has is found', $render('alpha') === 'module alpha');
    check('nested names work the same (admin/admin_alpha)', $render('admin/admin_alpha') === 'module admin');
    check('the site overrides a module template by name', $render('shared') === 'site shared');
    check('a name nobody has fails', $render('nobody') === '(failed)');
    put($tmp . '/outside.html', 'secret');
    symlink($tmp . '/outside.html', $mres . '/template/escape.html');
    check("a symlink out of a module's template/ is refused", $render('escape') === '(failed)');
    check('templateRoots(): the site first, then the modules',
        $engine->templateRoots() === [$site . '/template/', $mres . '/template/']);

    echo "\n3. Catalogs\n";
    put($mres . '/lang/en/Alpha.en.php', "<?php return ['a.title' => 'Module title', 'a.only' => 'Module only'];");
    put($site . '/lang/en/Alpha.en.php', "<?php return ['a.title' => 'Site title'];");
    $t = new Translator(new DiagnosticsCollector(), $roots, $paths);
    $t->load('Alpha');
    check("the site's key overrides the module's", $t->t('a.title') === 'Site title');
    check("the module's other keys are still there", $t->t('a.only') === 'Module only');
    put($mres . '/lang/en/Diagnostics/alpha.en.php',
        "<?php return ['astrx.alpha/x' => static fn(\$d, \$t): string => 'module x', 'astrx.alpha/y' => static fn(\$d, \$t): string => 'module y'];");
    put($site . '/lang/en/Diagnostics/alpha.en.php',
        "<?php return ['astrx.alpha/y' => static fn(\$d, \$t): string => 'site y'];");
    $r = new DiagnosticRenderer($t);
    $r->load('Diagnostics');
    $d = static fn(string $id): AbstractDiagnostic => new class($id, DiagnosticLevel::ERROR) extends AbstractDiagnostic {};
    check('module Diagnostics/ entries load', $r->render($d('astrx.alpha/x')) === 'module x');
    check("the site's Diagnostics/ entry overrides the module's", $r->render($d('astrx.alpha/y')) === 'site y');

    echo "\n4. Config\n";
    put($mres . '/config/Alpha.config.php', "<?php return ['AlphaConfig' => ['size' => 10, 'name' => 'module']];");
    $config = new Config(new DiagnosticsCollector(), null, $roots, $paths);
    $config->loadModuleConfig('Alpha');
    check("with no site file, the module's defaults apply", $config->getConfigInt('AlphaConfig', 'size') === 10);
    put($site . '/config/Alpha.config.php', "<?php return ['AlphaConfig' => ['size' => 20]];");
    $config->loadModuleConfig('Alpha');
    check("the site's file overrides a key", $config->getConfigInt('AlphaConfig', 'size') === 20);
    check('and keeps the module default for keys it does not set', $config->getConfigString('AlphaConfig', 'name') === 'module');
    $resolver = new ConfigDomainResolver($site . '/config/', $roots);
    put($mres . '/config/Lonely.config.php', "<?php return ['LonelySection' => ['k' => 1]];");
    check('a section only a module declares is routed to that file name (for writes)',
        $resolver->fileForSection('LonelySection') === 'Lonely');
    check('hasFile() sees module config files', $resolver->hasFile('Lonely') && !$resolver->hasFile('Nobody'));
    check('withConfigDir() keeps the module defaults', $resolver->withConfigDir($tmp . '/elsewhere/')->hasFile('Lonely'));

    echo "\n5. The language editor\n";
    put($mres . '/lang/en/Beta.en.php', "<?php return ['b.one' => 'One', 'b.two' => 'Two'];");
    $cat = new LangCatalog($roots, $paths);
    check("a module's domain is listed", in_array('Beta', $cat->domains(), true) && in_array('Alpha', $cat->domains(), true));
    $loaded = $cat->load('Alpha');
    check('load() shows the merged catalog',
        ($loaded['values']['en'] ?? []) === ['a.title' => 'Site title', 'a.only' => 'Module only']);
    $saved = $cat->save('Beta', ['en' => ['b.one' => 'Uno', 'b.two' => 'Two']]);
    check('save() succeeds', $saved->isOk());
    /** @var mixed $written */
    $written = require $site . '/lang/en/Beta.en.php';
    check("only the changed key lands in the site's file", $written === ['b.one' => 'Uno'], json_encode($written) ?: '');
    $moduleFile = require $mres . '/lang/en/Beta.en.php';
    check("the module's file is untouched", $moduleFile === ['b.one' => 'One', 'b.two' => 'Two']);
    $added = $cat->addLanguage('fr', 'en');
    check('addLanguage() succeeds', $added->isOk());
    /** @var mixed $fr */
    $fr = require $site . '/lang/fr/Beta.fr.php';
    check("the new locale gets the module's strings, with the site's overrides on top",
        $fr === ['b.one' => 'Uno', 'b.two' => 'Two'], json_encode($fr) ?: '');
    check("and the module's Diagnostics/ too", is_file($site . '/lang/fr/Diagnostics/alpha.fr.php'));
} finally {
    exec('rm -rf ' . escapeshellarg($tmp));
}

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
