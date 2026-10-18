<?php
declare(strict_types=1);

// Define constants that are normally set in public/index.php,
// so PHPStan can resolve defined() checks without running the app.

if (!defined('INDEX_DIR')) {
    define('INDEX_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
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

require_once __DIR__ . '/autoload.php';

(new AstrX\Prelude())->run();
