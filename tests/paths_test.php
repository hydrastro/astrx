<?php
declare(strict_types=1);

/**
 * AstrX\Support\Paths: the layout it builds, the constants it reads, and what
 * the framework does for an application that has no files of its own.
 *
 * Every class that reads or writes the installation's files is handed a Paths
 * instead of reading a global. An empty directory means "none": nothing may
 * then be read from, or written to, a path built by appending a file name to
 * '' — which resolves against the working directory or the filesystem root.
 *
 * Standalone: no bootstrap, no database, and no path constant is defined
 * (that is part of what is tested).
 */

use AstrX\Config\Config;
use AstrX\Config\ConfigDomainResolver;
use AstrX\Config\ConfigWriter;
use AstrX\I18n\LangCatalog;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Support\Paths;
use AstrX\Support\ResourceRoots;
use AstrX\Template\TemplateEngine;

$root = dirname(__DIR__);
require $root . '/src/autoload.php';
require_once __DIR__ . '/lib/scratch.php';

$PASS = 0;
$FAIL = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    $ok ? $PASS++ : $FAIL++;
    echo ($ok ? '  ok   - ' : '  FAIL - ') . $label . ($ok || $detail === '' ? '' : "\n         {$detail}") . "\n";
}

$S = DIRECTORY_SEPARATOR;

echo "1. Layout\n";
$p = Paths::standard('/srv/site');
check('standard(): root ends with a separator', $p->root === "/srv/site{$S}");
check('standard(): resources/', $p->resources === "/srv/site{$S}resources{$S}");
check('standard(): resources/config/', $p->config === "/srv/site{$S}resources{$S}config{$S}");
check('standard(): resources/template/', $p->templates === "/srv/site{$S}resources{$S}template{$S}");
check('standard(): resources/template/cache/', $p->templateCache === "/srv/site{$S}resources{$S}template{$S}cache{$S}");
check('standard(): resources/lang/', $p->lang === "/srv/site{$S}resources{$S}lang{$S}");
check('a directory given without a separator gets one', (new Paths(config: '/x/conf'))->config === "/x/conf{$S}");
check('…and one given with several keeps exactly one', (new Paths(config: '/x/conf//'))->config === "/x/conf{$S}");
check('an empty directory stays empty', (new Paths())->config === '' && (new Paths())->templates === '');

echo "\n2. The temp directory\n";
check("'' is the system temp directory", (new Paths())->tempDir() === sys_get_temp_dir());
check("'/' is not the filesystem root but the system temp directory", (new Paths(temp: '/'))->tempDir() === sys_get_temp_dir());
check('a set one is used, without a trailing separator', (new Paths(temp: '/var/astrx/'))->tempDir() === '/var/astrx');

echo "\n3. Constants\n";
check('fromConstants() with none defined is all empty',
    Paths::fromConstants() == new Paths(), var_export(Paths::fromConstants(), true));
foreach (['INDEX_DIR', 'RESOURCES_DIR', 'CONFIG_DIR', 'TEMPLATE_DIR', 'TEMPLATE_CACHE_DIR', 'LANG_DIR'] as $c) {
    check("{$c} is not defined by loading the framework", !defined($c));
}

echo "\n4. Storage directories\n";
$tmp = AstrX\TestSupport\scratchDir('astrx-paths-');
mkdir($tmp . '/resources', 0o700);
$ps = new Paths(resources: $tmp . '/resources');
check('an existing configured directory is kept', $ps->storageDir($tmp, 'up') === $tmp);
check('a configured directory whose parent exists is kept', $ps->storageDir($tmp . '/new', 'up') === $tmp . '/new');
check('an unusable one falls back to the resources dir', $ps->storageDir('/nonexistent/a/b', 'up') === $tmp . "/resources{$S}up");
check('with no resources dir, the configured value is returned as is',
    (new Paths())->storageDir('/nonexistent/a/b', 'up') === '/nonexistent/a/b');

echo "\n5. An application with no files of its own\n";
// A package's resources, and a working directory full of decoys: anything
// that appends a name to '' would find these instead of nothing.
$pkg = $tmp . '/pkg';
foreach (['lang/en', 'template', 'config'] as $d) { mkdir($pkg . '/' . $d, 0o700, true); }
file_put_contents($pkg . '/lang/en/Dom.en.php', "<?php return ['k' => 'package'];");
file_put_contents($pkg . '/template/pkgonly.html', 'from the package');
file_put_contents($pkg . '/config/Dom.config.php', "<?php return ['DomSection' => ['v' => 1]];");
$roots = new ResourceRoots([$pkg . '/']);
$cwd = $tmp . '/cwd';
foreach (['en', 'js'] as $d) { mkdir($cwd . '/' . $d, 0o700, true); }
file_put_contents($cwd . '/en/Dom.en.php', "<?php return ['k' => 'working directory'];");
file_put_contents($cwd . '/decoy.html', 'from the working directory');
file_put_contents($cwd . '/config.php', "<?php return ['DomSection' => ['v' => 99]];");
chdir($cwd);
$none = new Paths();

$t = new Translator(new DiagnosticsCollector(), $roots, $none);
$t->load('Dom');
check("Translator::load() reads the packages' catalog alone", $t->t('k') === 'package', $t->t('k'));

$engine = new TemplateEngine(new DiagnosticsCollector(), $roots, $none);
check('TemplateEngine searches only the packages', $engine->templateRoots() === [$pkg . '/template/']);
$rendered = $engine->renderTemplate('decoy');
check('…so a template in the working directory is not found', !$rendered->isOk());
$rendered = $engine->renderTemplate('pkgonly');
check("…a package's template is", $rendered->isOk() && $rendered->unwrap() === 'from the package');
check('…and nothing is cached in the working directory', glob($cwd . '/*.php') === [$cwd . '/config.php']);

$config = new Config(new DiagnosticsCollector(), null, $roots, $none);
$config->loadModuleConfig('Dom');
check("Config reads the packages' defaults, not a config.php in the working directory",
    $config->getConfigInt('DomSection', 'v') === 1);
check('configFilesFor() lists only the package file', $config->configFilesFor('Dom') === [$pkg . '/config/Dom.config.php']);

$writer = new ConfigWriter(new ConfigDomainResolver('', $roots));
$w = $writer->write('Dom', ['DomSection' => ['v' => 2]]);
check('ConfigWriter refuses to save with no config directory', $w->isErr());
check('…and wrote nothing', !is_file($cwd . '/Dom.config.php') && !is_file('/Dom.config.php'));
check('readSiteFile() is empty', $writer->readSiteFile('Dom') === []);

$catalog = new LangCatalog($roots, $none);
check('LangCatalog lists no locale of the site', $catalog->locales() === []);
check("…lists the packages' domains", in_array('Dom', $catalog->domains(), true));
$catalog->save('Dom', ['en' => ['k' => 'x']]);
check('…save() has no site locale to write',
    (require $cwd . '/en/Dom.en.php') === ['k' => 'working directory'] && !is_file('/en/Dom.en.php'));
try {
    $refused = $catalog->addLanguage('fr', 'en')->isErr();
} catch (LogicException) {
    $refused = true;
}
check('…and adding a language (a write) is refused', $refused && !is_dir($cwd . '/fr') && !is_dir('/fr'));

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
