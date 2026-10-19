<?php
declare(strict_types=1);

/**
 * Where the AstrX classes live: PSR-4 namespace prefix => source directories,
 * the same mapping the composer.json files declare. src/classloader.php loads
 * classes from it, and the tools that scan the source (architecture, config
 * and module checks, the compiled build) walk it.
 *
 * @return array<string, list<string>>
 */
return [
    'AstrX\\' => [__DIR__ . '/AstrX'],
];
