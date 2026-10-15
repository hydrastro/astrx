<?php
declare(strict_types=1);

// Load classes and support helpers without starting the CMS, sessions or a DB.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'AstrX\\')) { return; }
    $file = __DIR__ . '/AstrX/' . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) { require_once $file; }
});
require_once __DIR__ . '/AstrX/Support/constants.php';
