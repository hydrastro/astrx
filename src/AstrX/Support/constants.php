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
 * Publish a file so no reader can ever observe a partial one: write the bytes to
 * a unique temporary file in the same directory, then rename() it into place.
 *
 * rename() within one filesystem is atomic — a concurrent reader sees either the
 * whole old file or the whole new file, never a half-written one, and it needs no
 * lock of its own to get that guarantee.
 *
 * This exists because LOCK_EX does NOT give that guarantee. LOCK_EX is advisory:
 * it only excludes other writers that also ask for a lock. It does not stop
 * file_put_contents() from truncating the destination first, and a reader that
 * does a bare include/file_get_contents takes no lock at all, so it happily reads
 * the truncated file. TemplateEngine writes generated PHP classes into the
 * template cache and then require_once's them; a torn one raises a ParseError
 * that nothing catches, which the ErrorHandler turns into a 500. Because
 * .template-index.php is global, a torn index 500s EVERY page. The trigger is
 * ordinary: an operator clicks admin "Clear Cache" on a live site, or a deploy
 * touches a template, and the next burst of requests all miss, all compile and
 * all write the same file at once.
 *
 * Returns false (writing nothing) when the directory cannot be created, when the
 * bytes cannot be written in full — a short write from a full disk would
 * otherwise be renamed into place as a truncated file, exactly the failure this
 * function exists to prevent — or when the rename fails. Callers decide whether
 * a failed cache write is fatal; for caches it is not.
 *
 * It lives in this file rather than a new one because plain functions are not
 * autoloadable: src/bootstrap.php and the compiled Bundle both eagerly load
 * exactly Support/constants.php, so a second file would simply be absent from
 * the compiled single-file build.
 */
function atomicWrite(string $path, string $contents): bool
{
    $dir = \dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }

    // Same directory as the destination: rename() is only atomic within one
    // filesystem, and /tmp is very often a different one (tmpfs).
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));

    $written = @file_put_contents($tmp, $contents);
    if ($written === false || $written !== strlen($contents)) {
        @unlink($tmp);
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }

    // Published files include generated PHP that is require'd on the next
    // request. With opcache.validate_timestamps=Off (a common production
    // setting) opcache would keep serving the pre-rename compilation.
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }

    return true;
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
