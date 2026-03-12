<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'AstrX\\';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $file = CLASS_DIR . str_replace('\\', '/', substr($class, $len)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

new AstrX\Prelude();