<?php
declare(strict_types=1);

/**
 * Scratch-directory helpers for the standalone tests.
 *
 * Every test in tests/ writes somewhere under sys_get_temp_dir(), and each one
 * used to clean up with its own glob() + @rmdir() pair. Those pairs fail
 * SILENTLY — @rmdir() on a non-empty directory returns false and nobody looks —
 * and none of them descended into subdirectories, which is how
 * render_safety_test.php left 29 /tmp/astrx-render-safety-* trees behind on one
 * machine. One helper for all of them, so the next test that creates a
 * subdirectory does not get to rediscover this.
 *
 * Deliberately NOT named *_test.php: CI runs `for t in tests/*_test.php`, and
 * this file has nothing to assert.
 */

namespace AstrX\TestSupport;

/**
 * Delete $dir and everything below it — dotfiles and subdirectories included.
 *
 * scandir(), not glob(): glob() skips dotfiles unless asked twice, and the file
 * these tests kept leaking is TemplateEngine's .template-index.php.
 *
 * Symlinks are unlinked, never descended into: a test cleaning up after itself
 * must not delete whatever a link happens to point at.
 */
function rmTree(string $dir): void
{
    if (\is_link($dir) || !\is_dir($dir)) {
        @\unlink($dir);
        return;
    }

    foreach (\scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . \DIRECTORY_SEPARATOR . $entry;
        if (!\is_link($path) && \is_dir($path)) {
            rmTree($path);
        } else {
            @\unlink($path);
        }
    }

    @\rmdir($dir);
}

/**
 * Create a private per-run scratch directory, and remove it at shutdown even if
 * the test dies on the way — an early exit() or a fatal is exactly when the old
 * end-of-file cleanup was skipped.
 *
 * One caveat worth knowing, because it is what actually leaked: PHP runs
 * shutdown functions BEFORE object destructors. An object still alive at exit
 * whose destructor writes into the scratch dir — TemplateEngine flushes its
 * template-cache index from __destruct() — recreates part of the tree AFTER this
 * cleanup has run. Release those objects (unset()) at the end of the test: the
 * destructor then runs right there, before the removal.
 *
 * @param string $prefix e.g. 'astrx-render-safety-'
 * @return string absolute path, no trailing separator
 */
function scratchDir(string $prefix): string
{
    $dir = \rtrim(\sys_get_temp_dir(), '/\\') . \DIRECTORY_SEPARATOR
         . $prefix . \bin2hex(\random_bytes(6));

    if (!@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
        \fwrite(\STDERR, "cannot create the scratch directory {$dir}\n");
        exit(1);
    }

    \register_shutdown_function(static function () use ($dir): void {
        rmTree($dir);
    });

    return $dir;
}
