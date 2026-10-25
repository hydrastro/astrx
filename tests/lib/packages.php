<?php
declare(strict_types=1);

/**
 * Where a package's files are, for the tests: packages/<dir> in this
 * repository, vendor/<name> in an application that installs the packages with
 * Composer (the same rule src/sources.php follows). Deliberately NOT named
 * *_test.php: it has nothing to assert.
 */

namespace AstrX\TestSupport;

require_once dirname(__DIR__, 2) . '/tools/lib/sources.php';

/** Absolute directory of package $name (e.g. 'astrx/cms'); throws when it is not there. */
function packageDir(string $name): string
{
    $root = dirname(__DIR__, 2);
    $pkg  = \AstrX\Tools\packages($root)[$name] ?? null;
    if ($pkg === null) {
        throw new \LogicException("Package {$name} is neither in packages/ nor installed under vendor/.");
    }
    return $root . '/' . $pkg['dir'];
}
