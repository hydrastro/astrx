<?php
declare(strict_types=1);

namespace AstrX\Support;

/**
 * Typed accessors for string constants defined via define() in the bootstrap.
 *
 * PHPStan with a properly typed phpstan-bootstrap.php sees these constants as
 * non-falsy-string after the defined() guard, so is_string() is technically
 * redundant at that point. The phpstan-ignore suppresses the warning without
 * removing the safety net for environments where phpstan-bootstrap is absent.
 */
function configDir(): string
{
    if (!defined('CONFIG_DIR')) { return ''; }
    $v = \constant('CONFIG_DIR');
    // @phpstan-ignore-next-line
    return is_string($v) ? $v : '';
}

function templateDir(): string
{
    if (!defined('TEMPLATE_DIR')) { return ''; }
    $v = \constant('TEMPLATE_DIR');
    // @phpstan-ignore-next-line
    return is_string($v) ? $v : '';
}

function langDir(): string
{
    if (!defined('LANG_DIR')) { return ''; }
    $v = \constant('LANG_DIR');
    // @phpstan-ignore-next-line
    return is_string($v) ? $v : '';
}

function cacheDir(): string
{
    if (!defined('TEMPLATE_CACHE_DIR')) { return ''; }
    $v = \constant('TEMPLATE_CACHE_DIR');
    // @phpstan-ignore-next-line
    return is_string($v) ? $v : '';
}
