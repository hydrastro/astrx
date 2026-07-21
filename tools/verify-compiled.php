#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$bundle = $root . 'build' . DIRECTORY_SEPARATOR . 'astrx.compiled.php';

$fail = static function (string $message, int $code = 1) use ($root, $bundle): never {
    fwrite(STDERR, $message . PHP_EOL . PHP_EOL);
    fwrite(STDERR, "root:   {$root}" . PHP_EOL);
    fwrite(STDERR, "bundle: {$bundle}" . PHP_EOL);
    fwrite(STDERR, "run:    php tools/compile.php" . PHP_EOL);
    fwrite(STDERR, PHP_EOL . "Docker check:" . PHP_EOL);
    fwrite(STDERR, "  docker compose exec phpfpm php tools/verify-compiled.php" . PHP_EOL);
    fwrite(STDERR, "  docker compose exec phpfpm ls -lah /app/build" . PHP_EOL);
    exit($code);
};

if (!is_file($bundle)) {
    $fail('missing compiled bundle', 1);
}

if (!defined('INDEX_DIR')) {
    define('INDEX_DIR', $root);
}
foreach ([
    'RESOURCES_DIR' => INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR,
    'LANG_DIR' => INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR,
    'CONFIG_DIR' => INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR,
    'TEMPLATE_DIR' => INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR,
    'TEMPLATE_CACHE_DIR' => INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR,
    'SRC_DIR' => INDEX_DIR . 'src' . DIRECTORY_SEPARATOR,
    'CLASS_DIR' => INDEX_DIR . 'src' . DIRECTORY_SEPARATOR . 'AstrX' . DIRECTORY_SEPARATOR,
    'CONTROLLER_DIR' => INDEX_DIR . 'src' . DIRECTORY_SEPARATOR . 'controller' . DIRECTORY_SEPARATOR,
    'TEMPLATE_HANDLER_DIR' => INDEX_DIR . 'src' . DIRECTORY_SEPARATOR . 'template_handler' . DIRECTORY_SEPARATOR,
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

try {
    require $bundle;
} catch (Throwable $e) {
    $fail('compiled bundle failed while loading: ' . $e::class . ': ' . $e->getMessage(), 2);
}

$bundleClass = 'AstrX\\Compiled\\Bundle';
if (!class_exists($bundleClass, false)) {
    $fail('bundle loaded, but AstrX\\Compiled\\Bundle was not declared', 3);
}

$manifestCount = 0;
if (method_exists($bundleClass, 'resourceManifest')) {
    $manifest = $bundleClass::resourceManifest();
    $manifestCount = count($manifest);
}

$version = constant($bundleClass . '::VERSION');
$mode = defined($bundleClass . '::MODE') ? constant($bundleClass . '::MODE') : 'compiled-bundle';

echo "compiled bundle OK" . PHP_EOL;
echo "  class:     " . $bundleClass . PHP_EOL;
echo "  version:   " . (is_scalar($version) ? (string) $version : 'unknown') . PHP_EOL;
echo "  mode:      " . (is_scalar($mode) ? (string) $mode : 'compiled-bundle') . PHP_EOL;
echo "  bundle:    {$bundle}" . PHP_EOL;
echo "  size:      " . number_format((int) filesize($bundle)) . " bytes" . PHP_EOL;
echo "  resources: {$manifestCount}" . PHP_EOL;
echo "  php:       " . PHP_VERSION . PHP_EOL;
