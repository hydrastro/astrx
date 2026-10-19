<?php
declare(strict_types=1);

// The AstrX class autoloader, and nothing else: no constants, no helper
// functions, nothing started. Every entry point, tool and test registers it
// through this file, so where the classes live is decided in one place
// (src/sources.php). src/autoload.php adds the Support helper functions
// (src/support.php); a test that overrides one of them loads this file,
// defines its override, then src/support.php.
(static function (): void {
    /** @var array<string, list<string>> $sources */
    $sources = require __DIR__ . '/sources.php';
    // Longest prefix first, so a package's own namespace wins over 'AstrX\'.
    uksort($sources, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    spl_autoload_register(static function (string $class) use ($sources): void {
        foreach ($sources as $prefix => $dirs) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            foreach ($dirs as $dir) {
                if (is_file($dir . '/' . $relative)) {
                    require_once $dir . '/' . $relative;
                    return;
                }
            }
        }
    });
})();
