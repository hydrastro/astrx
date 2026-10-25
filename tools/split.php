<?php
declare(strict_types=1);

/**
 * Repository split — `php tools/split.php [options] [part…]`
 *
 * Extracts each part of this repository, with its history, onto a local
 * branch that can be pushed to a repository of its own:
 *
 *   - every Composer package in packages/ → split/<short> (short is the
 *     Composer name without the vendor: "framework", "module-chat");
 *   - the Rust workspace astrx-suite/ → split/astrx-suite;
 *   - the site — everything else: src/, public/, resources/, tools/, tests/,
 *     docs/ … — → split/<site>, where <site> is the root composer.json name
 *     without the vendor ("astrx"), with packages/ and astrx-suite/ filtered
 *     out of every commit.
 *
 * A package's (and the suite's) history follows each of its files back
 * through its renames, so it starts at the file's first commit — not where
 * the package's directory appeared, as `git subtree split` would
 * (tools/lib/history.php). Only git is needed.
 *
 * Without --run it lists what would be extracted. With --run it creates or
 * moves the split/* branches. A part is named by its Composer name, its short
 * name, its directory, or "site" / "suite"; none named means all of them.
 *
 * Options:
 *   --run              extract onto the split/* branches
 *   --repo-url=URL     where the parts will live, with {name} for the short
 *                      name, e.g. https://github.com/Vxmdzhb/{name} (or
 *                      https://github.com/Vxmdzhb/astrx-{name}). Required to
 *                      extract the site, and with it a final commit is added
 *                      on top of every extracted branch that makes the part
 *                      work as a repository of its own (tools/lib/split_adapt.php):
 *                        - a package: its homepage, where Composer finds the
 *                          packages it requires, and a CI workflow that
 *                          installs it alone and loads every class;
 *                        - the suite: its CI workflow at its own root;
 *                        - the site: Composer installs the packages from
 *                          their repositories and the class loader looks in
 *                          vendor/ only (tools/packages.php --installed); the
 *                          containers mount vendor/; the suite's compose file
 *                          is included from ../astrx-suite; CI installs the
 *                          packages first.
 *                      The commit is authored by this repository's
 *                      configured user.
 *   --push=URL         also push each branch to URL's main branch ({name} as
 *                      above, e.g. git@github.com:Vxmdzhb/{name}.git); implies
 *                      --run. The target repositories must exist (empty).
 *
 * Extraction rewrites commits, so the branches' commits carry no signature.
 * Sign them before pushing them anywhere they become the reference copy:
 *
 *     git switch split/framework
 *     git rebase --root --committer-date-is-author-date --exec 'git commit --amend --no-edit -S'
 *
 * The working tree is not touched (the branches are written straight into
 * the repository). Exit 0 on success, 1 on a failed git command, 2 on a bad argument.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

$root = dirname(__DIR__);
require_once __DIR__ . '/lib/sources.php';
require_once __DIR__ . '/lib/split_adapt.php';
require_once __DIR__ . '/lib/history.php';

$run     = false;
$push    = null;
$repoUrl = null;
$wanted  = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--run') {
        $run = true;
    } elseif (str_starts_with($arg, '--push=')) {
        $push = substr($arg, 7);
        $run  = true;
    } elseif (str_starts_with($arg, '--repo-url=')) {
        $repoUrl = substr($arg, 11);
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php tools/split.php [--run] [--repo-url=URL-with-{name}] [--push=URL-with-{name}] [part…]\n");
        exit(0);
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "ERROR: unknown option {$arg}\n");
        exit(2);
    } else {
        $wanted[] = $arg;
    }
}
foreach (['--push' => $push, '--repo-url' => $repoUrl] as $opt => $url) {
    if ($url !== null && !str_contains($url, '{name}')) {
        fwrite(STDERR, "ERROR: {$opt} needs a URL containing {name}, e.g. https://github.com/Vxmdzhb/{name}\n");
        exit(2);
    }
}
// It becomes composer.json "homepage" and the site's repository URLs, which
// Composer requires to be URLs.
if ($repoUrl !== null && preg_match('#^https?://#', $repoUrl) !== 1) {
    fwrite(STDERR, "ERROR: --repo-url must be an https:// URL, e.g. https://github.com/Vxmdzhb/{name}\n");
    exit(2);
}

/**
 * Run a git command in $dir; its trimmed stdout, or null on failure.
 *
 * @param array<string, string> $env
 */
function sp_git(string $dir, string $args, array $env = []): ?string
{
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }
    $out  = [];
    $code = 0;
    exec($prefix . 'git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "  git {$args}\n  " . implode("\n  ", $out) . "\n");
        return null;
    }
    return trim(implode("\n", $out));
}

/** The short name of a Composer name: "astrx/module-chat" → "module-chat". */
function sp_short(string $composerName): string
{
    return substr($composerName, (int) strpos($composerName, '/') + 1);
}

/** @return array<string, mixed> */
function sp_json(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $k => $v) {
        $out[(string) $k] = $v;
    }
    return $out;
}

// ── The parts ────────────────────────────────────────────────────────────────
if (!AstrX\Tools\packagesAreLocal($root)) {
    fwrite(STDERR, "ERROR: this repository holds no packages to split (they come from Composer here).\n");
    exit(2);
}
$rootJson  = sp_json($root . '/composer.json');
$siteShort = sp_short(is_string($rootJson['name'] ?? null) ? $rootJson['name'] : 'site/site');

$allPackages = AstrX\Tools\packages($root);
/** @var array<string, array{kind: string, dir: string, aliases: list<string>}> $parts short => part */
$parts = [];
foreach ($allPackages as $name => $pkg) {
    $parts[sp_short($name)] = ['kind' => 'package', 'dir' => $pkg['dir'], 'aliases' => [$name, $pkg['dir']]];
}
if (is_dir($root . '/astrx-suite')) {
    $parts['astrx-suite'] = ['kind' => 'suite', 'dir' => 'astrx-suite', 'aliases' => ['suite']];
}
$parts[$siteShort] = ['kind' => 'site', 'dir' => '', 'aliases' => ['site']];

if ($wanted !== []) {
    $selected = [];
    foreach ($wanted as $w) {
        $hit = null;
        foreach ($parts as $short => $part) {
            if ($w === $short || in_array($w, $part['aliases'], true)) {
                $hit = $short;
            }
        }
        if ($hit === null) {
            fwrite(STDERR, "ERROR: unknown part {$w}\n");
            exit(2);
        }
        $selected[$hit] = $parts[$hit];
    }
    $parts = $selected;
}

if (!$run) {
    foreach ($parts as $short => $part) {
        $what = match ($part['kind']) {
            'site'  => 'the site: everything but packages/ and astrx-suite/',
            default => $part['dir'] . '/',
        };
        fwrite(STDOUT, sprintf("  %-26s %s\n", 'split/' . $short, $what));
    }
    fwrite(STDOUT, "\nRun with --run to extract them (the site also needs --repo-url).\n");
    exit(0);
}
if (isset($parts[$siteShort]) && $repoUrl === null) {
    fwrite(STDERR, "ERROR: extracting the site needs --repo-url: it must say where its packages will be.\n");
    exit(2);
}

// The final commits are this repository's user's, like every other commit here.
$author = [];
foreach (['GIT_AUTHOR_NAME' => 'user.name', 'GIT_AUTHOR_EMAIL' => 'user.email'] as $env => $key) {
    $v = sp_git($root, 'config ' . $key);
    if ($v === null || $v === '') {
        fwrite(STDERR, "ERROR: git {$key} is not set in this repository.\n");
        exit(2);
    }
    $author[$env] = $v;
    $author[str_replace('AUTHOR', 'COMMITTER', $env)] = $v;
}

/** A scratch clone of HEAD to commit in, removed at exit. */
$scratch = static function () use ($root): ?string {
    $dir = rtrim(sys_get_temp_dir(), '/') . '/astrx-split-' . bin2hex(random_bytes(5));
    if (sp_git($root, 'clone --quiet --no-local ' . escapeshellarg($root) . ' ' . escapeshellarg($dir)) === null) {
        return null;
    }
    register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($dir)));
    return $dir;
};

/**
 * Commit $files (path relative to the work tree => new content) on top of
 * $sha in a scratch clone; the new commit's id, or null.
 *
 * @param array<string, ?string> $files null removes the file
 * @param array<string, string> $author
 * @param callable(): ?string $scratch
 */
function sp_commit_on(string $root, string $sha, array $files, string $message, array $author, callable $scratch): ?string
{
    $dir = $scratch();
    if ($dir === null || sp_git($dir, 'fetch --quiet ' . escapeshellarg($root) . ' ' . escapeshellarg($sha)) === null
        || sp_git($dir, 'checkout --quiet --detach ' . escapeshellarg($sha)) === null) {
        return null;
    }
    foreach ($files as $rel => $content) {
        if ($content === null) {
            if (is_file($dir . '/' . $rel) && sp_git($dir, 'rm --quiet -- ' . escapeshellarg($rel)) === null) {
                return null;
            }
            continue;
        }
        if (!is_dir(dirname($dir . '/' . $rel))) {
            mkdir(dirname($dir . '/' . $rel), 0o755, true);
        }
        file_put_contents($dir . '/' . $rel, $content);
        if (sp_git($dir, 'add -- ' . escapeshellarg($rel)) === null) {
            return null;
        }
    }
    $status = sp_git($dir, 'status --porcelain --untracked-files=no');
    if ($status === null) {
        return null;
    }
    if ($status === '') {
        return $sha; // nothing to change
    }
    if (sp_git($dir, '-c commit.gpgsign=false commit --quiet -m ' . escapeshellarg($message), $author) === null) {
        return null;
    }
    $new = sp_git($dir, 'rev-parse HEAD');
    return $new !== null && sp_git($root, 'fetch --quiet ' . escapeshellarg($dir) . ' ' . escapeshellarg($new)) !== null ? $new : null;
}

$failed  = false;
$renames = null;
foreach ($parts as $short => $part) {
    $branch = 'split/' . $short;

    // 1. Extract the history (tools/lib/history.php).
    try {
        if ($part['kind'] === 'site') {
            // Everything but the packages and the suite, under the same names.
            $map = static fn(string $path, string $commit): ?string =>
                str_starts_with($path, 'packages/') || str_starts_with($path, 'astrx-suite/') ? null : $path;
        } else {
            // The directory's files, following each back through its renames.
            $renames ??= AstrX\Tools\History\renames($root, 'HEAD');
            $map = AstrX\Tools\History\followDirectory($root, 'HEAD', $part['dir'], $renames);
        }
        $sha = AstrX\Tools\History\rewrite($root, 'HEAD', $map, 'refs/heads/' . $branch);
    } catch (RuntimeException $e) {
        fwrite(STDERR, "  {$short}: " . $e->getMessage() . "\n");
        $failed = true;
        continue;
    }

    // 2. Make it work as a repository of its own.
    if ($repoUrl !== null) {
        try {
            if ($part['kind'] === 'package') {
                $name    = $part['aliases'][0];
                $files   = AstrX\Tools\Split\package($name, $allPackages, $repoUrl);
                $message = "Make the package a repository of its own\n\n"
                    . "Its homepage is this repository, Composer finds the packages it\n"
                    . "requires in theirs, and CI installs it alone and loads every class.";
            } elseif ($part['kind'] === 'suite') {
                $files   = AstrX\Tools\Split\suite($root);
                $message = "Run the suite's CI from its own root\n\n"
                    . "The workflow ran inside astrx-suite/ of the repository it shared with\n"
                    . "the site. The job that guarded the boundary between the two goes:\n"
                    . "separate repositories are that boundary. rust.yml, the inactive\n"
                    . "predecessor GitHub never ran from the subdirectory, goes too.";
            } else {
                $gen = rtrim(sys_get_temp_dir(), '/') . '/astrx-split-gen-' . bin2hex(random_bytes(5));
                mkdir($gen . '/src', 0o700, true);
                $genOut  = [];
                $genCode = 0;
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/packages.php')
                    . ' --installed ' . escapeshellarg('--write-to=' . $gen) . ' 2>&1', $genOut, $genCode);
                if ($genCode !== 0) {
                    throw new RuntimeException("tools/packages.php --installed failed:\n" . implode("\n", $genOut));
                }
                $generated = [];
                foreach (['sources.php', 'support.php', 'resources.php'] as $f) {
                    $generated['src/' . $f] = (string) file_get_contents($gen . '/src/' . $f);
                }
                exec('rm -rf ' . escapeshellarg($gen));
                $files   = AstrX\Tools\Split\site($root, $rootJson, $allPackages, $repoUrl, $short, $generated);
                $message = "Get the packages from their own repositories\n\n"
                    . "The packages and the Rust suite no longer live in this repository.\n"
                    . "Composer installs the packages from their repositories, the class\n"
                    . "loader finds them under vendor/, the containers mount vendor/, and\n"
                    . "the suite's services come from a checkout next to this one\n"
                    . "(../astrx-suite). CI installs the packages before it runs anything.";
            }
        } catch (RuntimeException $e) {
            fwrite(STDERR, "  {$short}: " . $e->getMessage() . "\n");
            $failed = true;
            continue;
        }
        $new = sp_commit_on($root, $sha, $files, $message, $author, $scratch);
        if ($new === null) {
            $failed = true;
            continue;
        }
        $sha = $new;
    }

    if (sp_git($root, 'branch -f ' . escapeshellarg($branch) . ' ' . escapeshellarg($sha)) === null) {
        $failed = true;
        continue;
    }
    $line = sprintf('%-26s %s', $branch, substr($sha, 0, 10));

    // 3. Push.
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
