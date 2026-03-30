<?php
declare(strict_types=1);

namespace AstrX\Support;

/**
 * Typed accessors for string constants defined via define() in the bootstrap.
 * Using constant() + is_string() to satisfy PHPStan level 9/10.
 */
function configDir(): string
{
    if (!defined('CONFIG_DIR')) { return ''; }
    $v = \constant('CONFIG_DIR');
    return is_string($v) ? $v : '';
}

function templateDir(): string
{
    if (!defined('TEMPLATE_DIR')) { return ''; }
    $v = \constant('TEMPLATE_DIR');
    return is_string($v) ? $v : '';
}

function langDir(): string
{
    if (!defined('LANG_DIR')) { return ''; }
    $v = \constant('LANG_DIR');
    return is_string($v) ? $v : '';
}

function cacheDir(): string
{
    if (!defined('TEMPLATE_CACHE_DIR')) { return ''; }
    $v = \constant('TEMPLATE_CACHE_DIR');
    return is_string($v) ? $v : '';
}
