<?php

declare(strict_types = 1);

spl_autoload_register(function (string $class)
: void {
    $class_file = CLASS_DIR . $class . ".php";
    if (file_exists($class_file)) {
        require $class_file;
    }
});
new Prelude();
