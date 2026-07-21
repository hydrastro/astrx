<?php
declare(strict_types=1);

/**
 * Warm AstrX server-side template cache without booting the web application.
 *
 * This compiles all HTML templates under resources/template into resources/template/cache/*.php
 * and writes the TemplateEngine persistent cache index, so production requests
 * can require compiled template classes without reading/parsing template source.
 */

$root = dirname(__DIR__);
$quiet = in_array('--quiet', $argv, true);
$clear = in_array('--clear', $argv, true);

if (!defined('INDEX_DIR')) {
    define('INDEX_DIR', $root . DIRECTORY_SEPARATOR);
}
if (!defined('RESOURCES_DIR')) {
    define('RESOURCES_DIR', INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR);
}
if (!defined('LANG_DIR')) {
    define('LANG_DIR', RESOURCES_DIR . 'lang' . DIRECTORY_SEPARATOR);
}
if (!defined('CONFIG_DIR')) {
    define('CONFIG_DIR', RESOURCES_DIR . 'config' . DIRECTORY_SEPARATOR);
}
if (!defined('TEMPLATE_DIR')) {
    define('TEMPLATE_DIR', RESOURCES_DIR . 'template' . DIRECTORY_SEPARATOR);
}
if (!defined('TEMPLATE_CACHE_DIR')) {
    define('TEMPLATE_CACHE_DIR', TEMPLATE_DIR . 'cache' . DIRECTORY_SEPARATOR);
}
if (!defined('SRC_DIR')) {
    define('SRC_DIR', INDEX_DIR . 'src' . DIRECTORY_SEPARATOR);
}
if (!defined('CLASS_DIR')) {
    define('CLASS_DIR', SRC_DIR . 'AstrX' . DIRECTORY_SEPARATOR);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'AstrX\\';
    $len = strlen($prefix);
    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }
    $file = CLASS_DIR . str_replace('\\', '/', substr($class, $len)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$supportConstants = CLASS_DIR . 'Support/constants.php';
if (is_file($supportConstants)) {
    require_once $supportConstants;
}

if (!is_dir(TEMPLATE_DIR)) {
    fwrite(STDERR, "Missing template directory: " . TEMPLATE_DIR . PHP_EOL);
    exit(1);
}

if (!is_dir(TEMPLATE_CACHE_DIR) && !mkdir(TEMPLATE_CACHE_DIR, 0775, true) && !is_dir(TEMPLATE_CACHE_DIR)) {
    fwrite(STDERR, "Could not create template cache directory: " . TEMPLATE_CACHE_DIR . PHP_EOL);
    exit(1);
}

$collector = new AstrX\Result\DiagnosticsCollector();
$engine = new AstrX\Template\TemplateEngine($collector);
$engine->setTemplateDir(TEMPLATE_DIR);
$engine->setTemplateCacheDir(TEMPLATE_CACHE_DIR);
$engine->setTemplateExtension('.html');
$engine->setParseMode(AstrX\Template\TemplateEngine::PARSE_MODE_TEMPLATE);
$engine->setCacheTemplates(true);

if ($clear) {
    $deleted = $engine->clearCache();
    if (!$quiet) {
        echo "Cleared {$deleted} cached template files/index entries.\n";
    }
}

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(TEMPLATE_DIR, FilesystemIterator::SKIP_DOTS),
);

$templates = [];
foreach ($rii as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'html') {
        continue;
    }

    $path = $file->getPathname();
    $rel = substr($path, strlen(TEMPLATE_DIR));
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    if (str_starts_with($rel, 'cache/')) {
        continue;
    }
    $templates[] = substr($rel, 0, -5); // drop .html
}

sort($templates);
$ok = 0;
$failed = 0;

foreach ($templates as $template) {
    $loaded = $engine->loadTemplate($template);
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

unset($engine); // force TemplateEngine::__destruct() to flush .template-index.php

$count = count($collector->diagnostics());

if (!$quiet) {
    echo "Template cache warm complete: {$ok} compiled, {$failed} failed, {$count} diagnostics.\n";
    echo "Cache dir: " . TEMPLATE_CACHE_DIR . PHP_EOL;
}

exit($failed === 0 ? 0 : 1);
