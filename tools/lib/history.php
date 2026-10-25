<?php
declare(strict_types=1);

/**
 * History extraction for tools/split.php: rewrite a repository's history so
 * it holds only some of its files, under new names, keeping every commit
 * that touched them — with git itself (fast-export | filter | fast-import),
 * no other tool.
 *
 * Unlike `git subtree split`, which follows one directory and so cuts a
 * package's history where its directory appeared, this follows each file
 * back through its renames: a package's history goes back to the file's
 * first commit, wherever it lived then. A former name only counts in the
 * commits BEFORE the rename (the rename commit's ancestors), so a file that
 * something else later re-created at that old path is not swept in.
 *
 * Rewritten commits keep their author, committer, dates and message. Their
 * signatures cannot be kept: the signed content changed.
 */

namespace AstrX\Tools\History;

/** Run git in $repo; stdout (untrimmed), or throw. */
function git(string $repo, string $args, ?string $stdin = null): string
{
    $proc = proc_open(
        'git -C ' . escapeshellarg($repo) . ' ' . $args,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($proc)) {
        throw new \RuntimeException("git {$args}: could not start");
    }
    // Write stdin in one go and read stdout/stderr concurrently enough for
    // our sizes: fast-import's output is small, fast-export's input is none.
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        throw new \RuntimeException("git {$args}:\n{$err}");
    }
    return $out;
}

/**
 * Every rename git detects in $rev's history, newest first:
 * [commit, old path, new path]. Only confident ones (similarity ≥ $min %),
 * so two small unrelated files are not taken for one.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function renames(string $repo, string $rev, int $min = 75): array
{
    $log = git($repo, '-c core.quotePath=false log -z -M' . $min . '% --diff-filter=R --name-status --format=%x01%H ' . escapeshellarg($rev));
    $out = [];
    foreach (explode("\x01", $log) as $chunk) {
        if ($chunk === '') {
            continue;
        }
        $fields = explode("\0", $chunk);
        $commit = trim(array_shift($fields));
        // -z: status, old, new, status, old, new, … (statuses start with R).
        for ($i = 0; $i + 2 < count($fields); $i += 3) {
            $status = trim($fields[$i]);
            if ($status !== '' && $status[0] === 'R') {
                $out[] = [$commit, $fields[$i + 1], $fields[$i + 2]];
            }
        }
    }
    return $out;
}

/**
 * A path mapper for the files under $dir at $rev, renamed to drop $dir, and
 * for every former name of those files in the commits before it was renamed.
 * Returns fn(string $path, string $commit): ?string — the new path, or null
 * when the path is not part of the extraction.
 *
 * @param list<array{0: string, 1: string, 2: string}> $renames from renames()
 * @return \Closure(string, string): ?string
 */
function followDirectory(string $repo, string $rev, string $dir, array $renames): \Closure
{
    $dir = rtrim($dir, '/') . '/';
    // Current name => final name, then every former name with the commit it
    // was renamed in.
    $final = [];
    foreach (explode("\0", git($repo, 'ls-tree -r -z --name-only ' . escapeshellarg($rev) . ' -- ' . escapeshellarg($dir))) as $p) {
        if ($p !== '') {
            $final[$p] = substr($p, strlen($dir));
        }
    }
    /** @var array<string, list<array{0: string, 1: string}>> $former old name => [[final, renamed in], …] */
    $former = [];
    $names  = $final; // name => final, for names whose older names we still look for
    foreach ($renames as [$commit, $old, $new]) {
        if (isset($names[$new])) {
            $former[$old][] = [$names[$new], $commit];
            $names[$old] = $names[$new];
        }
    }

    /** @var array<string, array<string, true>> $ancestors renamed-in commit => its proper ancestors */
    $ancestors = [];
    $isBefore = static function (string $commit, string $renamedIn) use (&$ancestors, $repo): bool {
        if (!isset($ancestors[$renamedIn])) {
            $list = git($repo, 'rev-list ' . escapeshellarg($renamedIn) . '^@ --');
            $ancestors[$renamedIn] = array_fill_keys(array_filter(explode("\n", $list)), true);
        }
        return isset($ancestors[$renamedIn][$commit]);
    };

    return static function (string $path, string $commit) use ($dir, $former, $isBefore): ?string {
        if (str_starts_with($path, $dir)) {
            return substr($path, strlen($dir));
        }
        foreach ($former[$path] ?? [] as [$to, $renamedIn]) {
            if ($isBefore($commit, $renamedIn)) {
                return $to;
            }
        }
        return null;
    };
}

/** Unquote a fast-export path ("…" with C escapes) or return it as is. */
function unquote(string $p): string
{
    if ($p === '' || $p[0] !== '"') {
        return $p;
    }
    return stripcslashes(substr($p, 1, -1));
}

/** Quote a path for fast-import when it has to be. */
function quote(string $p): string
{
    if (!preg_match('/["\\\\\n]/', $p) && $p[0] !== '"') {
        return $p;
    }
    return '"' . addcslashes($p, "\"\\\n") . '"';
}

/**
 * Rewrite $rev's history keeping only what $map keeps, onto the new ref
 * $ref in the same repository (which already has every blob). Commits that
 * end up changing nothing are dropped. Returns the new tip's id.
 *
 * @param \Closure(string, string): ?string $map path, original commit => new path or null
 */
function rewrite(string $repo, string $rev, \Closure $map, string $ref): string
{
    $stream = git($repo, '-c core.quotePath=false fast-export --no-data --show-original-ids --reencode=yes '
        . '--signed-tags=strip --tag-of-filtered-object=drop ' . escapeshellarg($rev));

    $out     = '';
    /** @var array<string, string> $alias dropped mark => the mark it stands for ('' = no parent) */
    $alias   = [];
    $resolve = static function (string $mark) use (&$alias): string {
        while (isset($alias[$mark])) {
            $mark = $alias[$mark];
        }
        return $mark;
    };
    $pos = 0;
    $len = strlen($stream);
    $line = static function () use ($stream, &$pos, $len): ?string {
        if ($pos >= $len) {
            return null;
        }
        $nl = strpos($stream, "\n", $pos);
        $l  = $nl === false ? substr($stream, $pos) : substr($stream, $pos, $nl - $pos);
        $pos = $nl === false ? $len : $nl + 1;
        return $l;
    };

    $tip = '';
    while (($l = $line()) !== null) {
        if (!str_starts_with($l, 'commit ')) {
            // reset / feature / done / progress: refs renamed, rest dropped.
            continue;
        }
        $mark = $orig = '';
        $head = [];
        $from = null;
        $merges = [];
        $changes = [];
        while (($l = $line()) !== null && $l !== '') {
            if (str_starts_with($l, 'mark ')) {
                $mark = substr($l, 5);
            } elseif (str_starts_with($l, 'original-oid ')) {
                $orig = substr($l, 13);
            } elseif (str_starts_with($l, 'author ') || str_starts_with($l, 'committer ') || str_starts_with($l, 'encoding ')) {
                $head[] = $l;
            } elseif (str_starts_with($l, 'data ')) {
                $n = (int) substr($l, 5);
                $head[] = $l . "\n" . substr($stream, $pos, $n);
                $pos += $n;
                if ($pos < $len && $stream[$pos] === "\n") {
                    $pos++;
                }
            } elseif (str_starts_with($l, 'from ')) {
                $from = substr($l, 5);
            } elseif (str_starts_with($l, 'merge ')) {
                $merges[] = substr($l, 6);
            } elseif (str_starts_with($l, 'M ')) {
                $parts = explode(' ', $l, 4);
                $to = $map(unquote($parts[3]), $orig);
                if ($to !== null && $to !== '') {
                    $changes[] = "M {$parts[1]} {$parts[2]} " . quote($to);
                }
            } elseif (str_starts_with($l, 'D ')) {
                $to = $map(unquote(substr($l, 2)), $orig);
                if ($to !== null && $to !== '') {
                    $changes[] = 'D ' . quote($to);
                }
            } elseif ($l === 'deleteall') {
                $changes[] = 'deleteall';
            }
            // gpgsig and anything else: dropped (a signature over rewritten content is void).
        }

        $tip = $mark; // topological order: $rev itself comes last
        $parents = [];
        foreach ([$from, ...$merges] as $p) {
            if ($p === null) {
                continue;
            }
            $r = $resolve($p);
            if ($r !== '' && !in_array($r, $parents, true)) {
                $parents[] = $r;
            }
        }
        if ($changes === [] && count($parents) <= 1) {
            $alias[$mark] = $parents[0] ?? '';
            continue;
        }
        if ($parents === []) {
            $out .= "reset {$ref}\n";
        }
        $out .= "commit {$ref}\nmark {$mark}\n" . implode("\n", $head) . "\n";
        if ($parents !== []) {
            $out .= 'from ' . array_shift($parents) . "\n";
            foreach ($parents as $p) {
                $out .= "merge {$p}\n";
            }
        }
        $out .= ($changes === [] ? '' : implode("\n", $changes) . "\n") . "\n";
    }

    $last = $tip === '' ? '' : $resolve($tip);
    if ($out === '' || $last === '') {
        throw new \RuntimeException("nothing of {$rev} is kept");
    }
    // The ref ends at $rev's counterpart: the last commit written is not it
    // when $rev itself changed nothing kept here.
    $out .= "reset {$ref}\nfrom {$last}\n\n";
    git($repo, 'fast-import --quiet --force', $out . "done\n");
    return trim(git($repo, 'rev-parse ' . escapeshellarg($ref)));
}
