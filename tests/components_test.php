<?php
declare(strict_types=1);
ob_start();
require dirname(__DIR__) . '/examples/components.php';
$output = ob_get_clean();
if ($output !== "42\nHello from AstrX\n" || class_exists(AstrX\Prelude::class, false)
    || session_status() !== PHP_SESSION_NONE) {
    throw new RuntimeException('Component example booted the application or returned unexpected output');
}
echo "Component example works without application startup\n";
