<?php
declare(strict_types=1);

// Paths (public/index.php normally defines them first), then classes.
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/autoload.php';

(new AstrX\Prelude())->run();
