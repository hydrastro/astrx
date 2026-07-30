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
function indexDir(): string
{
    if (!defined('INDEX_DIR')) { return ''; }
    $v = \constant('INDEX_DIR');
    return is_string($v) ? $v : '';
}

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

/**
 * Resolve a writable storage directory portably.
 *
 * Prefers the configured path when it already exists, or when its parent exists
 * (so mkdir can create it) — this keeps a Docker "/app/resources/…" mount
 * working. Otherwise falls back to the bundled resources dir
 * (RESOURCES_DIR/<subdir>, else <repo-root>/resources/<subdir>), so a non-Docker
 * deploy (CI, bare-metal) does not fail every upload the way a hardcoded
 * "/app/…" path does — the same failure class as the captcha font bug. Returns
 * the configured value unchanged as a last resort.
 */
function resourceStorageDir(string $configured, string $subdir): string
{
    $configured = rtrim($configured, '/\\');
    if ($configured !== '') {
        if (is_dir($configured)) {
            return $configured;
        }
        $parent = \dirname($configured);
        if ($parent !== '' && $parent !== $configured && is_dir($parent)) {
            return $configured; // parent exists → mkdir($configured) can succeed
        }
    }

    $bases = [];
    if (defined('RESOURCES_DIR')) {
        $res = \constant('RESOURCES_DIR');
        if (is_string($res) && $res !== '') {
            $bases[] = rtrim($res, '/\\');
        }
    }
    $bases[] = \dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources';

    foreach ($bases as $base) {
        if (is_dir($base)) {
            return $base . DIRECTORY_SEPARATOR . $subdir;
        }
    }

    return $configured;
}
