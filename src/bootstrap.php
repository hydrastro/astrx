<?php

declare(strict_types = 1);

spl_autoload_register(function (string $class)
: void {
    require CLASS_DIR . $class . ".php";
});
new Prelude();
