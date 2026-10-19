<?php
declare(strict_types=1);

/**
 * Warm AstrX server-side template cache without booting the web application.
 *
 * This compiles all HTML templates under resources/template — and every
 * installed module's template/ directory — into resources/template/cache/*.php
 * using the configured source, mode and content-addressed cache identity. A
 * name the site overrides is compiled once, from the site's file.
 */

$root = dirname(__DIR__);
$quiet = in_array('--quiet', $argv, true);
$clear = in_array('--clear', $argv, true);

require_once $root . '/src/paths.php';
require_once $root . '/src/autoload.php';

$collector = new AstrX\Result\DiagnosticsCollector();
$paths = AstrX\Support\Paths::fromConstants();
$roots = AstrX\ApplicationContainer::resourceRoots($paths);
$engine = new AstrX\Template\TemplateEngine($collector, $roots, $paths);
// The TemplateEngine section as the site resolves it: the framework's default,
// overridden key by key by the site's resources/config/TemplateEngine.config.php.
// An empty directory keeps the application's own (the engine's setters skip it).
$config = new AstrX\Config\Config($collector, null, $roots, $paths);
$config->loadModuleConfig('TemplateEngine');
$cfg = $config->getConfigSection('TemplateEngine');
$engine->setTemplateDir(is_string($cfg['template_dir'] ?? null) ? $cfg['template_dir'] : '');
$engine->setTemplateCacheDir(is_string($cfg['template_cache_dir'] ?? null) ? $cfg['template_cache_dir'] : '');
$engine->setTemplateExtension(is_string($cfg['template_extension'] ?? null) ? $cfg['template_extension'] : '.html');
$engine->setParseMode(is_int($cfg['parse_mode'] ?? null) ? $cfg['parse_mode'] : 1);
$engine->setCacheTemplates(true);
$templateDir = rtrim($engine->getTemplateDir(), '/\\') . DIRECTORY_SEPARATOR;
$extension = $engine->getTemplateExtension();
if (!is_dir($templateDir) || $extension === '') { fwrite(STDERR, "Invalid template configuration\n"); exit(1); }

if ($clear) {
    $deleted = $engine->clearCache();
    if (!$quiet) {
        echo "Cleared {$deleted} cached template files/index entries.\n";
    }
}

$templates = [];
foreach ($engine->templateRoots() as $i => $rootDir) {
    $rootDir = rtrim($rootDir, '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($rootDir)) {
        continue;
    }
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($rii as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), $extension)) {
            continue;
        }

        $path = $file->getPathname();
        $rel = substr($path, strlen($rootDir));
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        if ($i === 0 && str_starts_with($rel, 'cache/')) {
            continue;
        }
        $templates[substr($rel, 0, -strlen($extension))] = true;
    }
}
$templates = array_keys($templates);

sort($templates);
$ok = 0;
$failed = 0;

foreach ($templates as $template) {
    $loaded = $engine->loadTemplate($template, $engine->getParseMode());
    if ($loaded !== null) {
        $ok++;
        if (!$quiet) {
            echo "compiled {$template}\n";
        }
        continue;
    }

    $failed++;
    fwrite(STDERR, "failed {$template}\n");
}



$count = count($collector->diagnostics());

if (!$quiet) {
    echo "Template cache warm complete: {$ok} compiled, {$failed} failed, {$count} diagnostics.\n";
    echo "Cache dir: " . $engine->getTemplateCacheDir() . PHP_EOL;
}

exit($failed === 0 ? 0 : 1);
