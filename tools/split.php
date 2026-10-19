<?php
declare(strict_types=1);

/**
 * Package split — `php tools/split.php [--run] [--push=URL] [package…]`
 *
 * Every package in packages/ can be published as its own read-only
 * repository, the way framework monorepos publish their components: the
 * monorepo stays where changes are made, and each package's directory is
 * extracted, with its history, onto a branch that is pushed to that package's
 * repository.
 *
 * Without --run this prints, for every package (or the ones named, by
 * Composer name or directory), the git command that extracts it. With --run it
 * extracts each onto the local branch split/<short-name>, where short-name is
 * the Composer name without the vendor ("framework", "module-chat"). With
 * --push=URL (implies --run) it also pushes that branch to URL's main branch;
 * {name} in URL becomes the short name, e.g.
 *
 *     php tools/split.php --push=git@github.com:astrx/{name}.git
 *
 * The extraction is `git subtree split` from HEAD: deterministic, so splitting
 * again after new commits only adds them, and a mirror can always be
 * fast-forwarded. A package's history starts where its directory first
 * appeared under packages/ (commits from before the move into packages are not
 * part of it).
 *
 * The working tree is not touched. Exit 0 on success, 1 on a failed git
 * command, 2 on a bad argument.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

$root = dirname(__DIR__);
require_once __DIR__ . '/lib/sources.php';

$run   = in_array('--run', $argv, true);
$push  = null;
$wanted = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--push=')) {
        $push = substr($arg, 7);
        $run  = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php tools/split.php [--run] [--push=URL-with-{name}] [package…]\n");
        exit(0);
    } elseif ($arg !== '--run') {
        $wanted[] = $arg;
    }
}
if ($push !== null && !str_contains($push, '{name}')) {
    fwrite(STDERR, "ERROR: --push needs a URL containing {name}, e.g. git@github.com:astrx/{name}.git\n");
    exit(2);
}

$packages = AstrX\Tools\packages($root);
if ($wanted !== []) {
    $packages = array_filter(
        $packages,
        static fn(array $pkg, string $name): bool => in_array($name, $wanted, true) || in_array($pkg['dir'], $wanted, true),
        ARRAY_FILTER_USE_BOTH,
    );
    if (count($packages) !== count($wanted)) {
        fwrite(STDERR, "ERROR: unknown package among: " . implode(', ', $wanted) . "\n");
        exit(2);
    }
}

/** Run a git command in the repository; its trimmed stdout, or null on failure. */
function sp_git(string $root, string $args): ?string
{
    $out  = [];
    $code = 0;
    exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "  git {$args}\n  " . implode("\n  ", $out) . "\n");
        return null;
    }
    return trim(implode("\n", $out));
}

$failed = false;
foreach ($packages as $name => $pkg) {
    $short  = substr($name, (int) strpos($name, '/') + 1);
    $branch = 'split/' . $short;
    $split  = 'subtree split --prefix=' . escapeshellarg($pkg['dir']) . ' HEAD';
    if (!$run) {
        fwrite(STDOUT, "git {$split}   # {$name} → {$branch}\n");
        continue;
    }
    $sha = sp_git($root, $split);
    if ($sha === null || preg_match('/([0-9a-f]{40})\s*$/', $sha, $m) !== 1) {
        $failed = true;
        continue;
    }
    if (sp_git($root, 'branch -f ' . escapeshellarg($branch) . ' ' . $m[1]) === null) {
        $failed = true;
        continue;
    }
    $line = sprintf('%-26s %s  %s', $name, substr($m[1], 0, 10), $branch);
    if ($push !== null) {
        $url = str_replace('{name}', $short, $push);
        if (sp_git($root, 'push ' . escapeshellarg($url) . ' ' . escapeshellarg($branch . ':refs/heads/main')) === null) {
            $failed = true;
            continue;
        }
        $line .= '  → ' . $url;
    }
    fwrite(STDOUT, $line . "\n");
}
exit($failed ? 1 : 0);
