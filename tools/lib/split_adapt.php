<?php
declare(strict_types=1);

/**
 * What tools/split.php changes in each extracted part so it works as a
 * repository of its own. Each function returns the files to write
 * (path => content) or remove (path => null), relative to the part's root.
 *
 * Every edit of a file the monorepo also has is an exact replacement that
 * must apply exactly once: when that file has changed here, the split stops
 * with the file's name instead of producing a repository that silently
 * misses the edit. Fix the replacement here, then split again.
 */

namespace AstrX\Tools\Split;

/**
 * Apply [old, new] replacements to $content; each old text must occur
 * exactly once.
 *
 * @param list<array{0: string, 1: string}> $edits
 */
function edit(string $file, string $content, array $edits): string
{
    foreach ($edits as [$old, $new]) {
        $count = substr_count($content, $old);
        if ($count !== 1) {
            throw new \RuntimeException(
                "{$file}: expected this text exactly once, found it {$count} time(s) — "
                . "update tools/lib/split_adapt.php:\n" . $old
            );
        }
        $content = str_replace($old, $new, $content);
    }
    return $content;
}

/** Remove every line of $content that contains $needle; at least one must. */
function dropLines(string $file, string $content, string $needle): string
{
    $lines = explode("\n", $content);
    $kept  = array_values(array_filter($lines, static fn(string $l): bool => !str_contains($l, $needle)));
    if (count($kept) === count($lines)) {
        throw new \RuntimeException("{$file}: no line contains '{$needle}' — update tools/lib/split_adapt.php");
    }
    return implode("\n", $kept);
}

/** @param array<string, mixed> $data */
function json(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/** "astrx/module-chat" → "module-chat". */
function short(string $composerName): string
{
    return substr($composerName, (int) strpos($composerName, '/') + 1);
}

/**
 * The repository URL of a part: $repoUrl with {name} replaced.
 */
function repo(string $repoUrl, string $short): string
{
    return str_replace('{name}', $short, $repoUrl);
}

/**
 * The astrx/* packages $name requires, transitively, not including itself.
 *
 * @param array<string, array{dir: string, json: array<string, mixed>}> $packages
 * @return list<string>
 */
function astrxRequires(string $name, array $packages): array
{
    $seen = [];
    $todo = [$name];
    while ($todo !== []) {
        $n = array_pop($todo);
        foreach (array_keys((array) ($packages[$n]['json']['require'] ?? [])) as $req) {
            $req = (string) $req;
            if (isset($packages[$req]) && !isset($seen[$req])) {
                $seen[$req] = true;
                $todo[] = $req;
            }
        }
    }
    $out = array_keys($seen);
    sort($out);
    return $out;
}

// ── A package ────────────────────────────────────────────────────────────────

/**
 * A package's repository: its homepage, where Composer finds the packages it
 * requires when it is installed on its own (a non-root package's
 * `repositories` is ignored, so this only affects the package's own CI), and
 * a CI workflow that installs it alone and loads every class.
 *
 * @param array<string, array{dir: string, json: array<string, mixed>}> $packages
 * @return array<string, ?string>
 */
function package(string $name, array $packages, string $repoUrl): array
{
    $json = $packages[$name]['json'];
    $json['homepage'] = repo($repoUrl, short($name));
    $deps = astrxRequires($name, $packages);
    if ($deps !== []) {
        $json['repositories'] = array_map(
            static fn(string $d): array => ['type' => 'vcs', 'url' => repo($repoUrl, short($d))],
            $deps,
        );
    }

    $extensions = [];
    foreach (array_keys((array) ($json['require'] ?? [])) as $req) {
        if (str_starts_with((string) $req, 'ext-')) {
            $extensions[] = substr((string) $req, 4);
        }
    }
    sort($extensions);
    $ext = implode(', ', $extensions);

    $ci = <<<YAML
    name: CI

    on:
      push:
      pull_request:

    permissions:
      contents: read

    jobs:
      check:
        name: Installs alone, every file parses, every class loads
        runs-on: ubuntu-latest
        env:
          # The packages require each other at the same version (self.version);
          # a pull request's detached checkout has no branch to guess it from.
          COMPOSER_ROOT_VERSION: dev-main
        steps:
          - uses: actions/checkout@v4

          - name: Set up PHP 8.4
            uses: shivammathur/setup-php@v2
            with:
              php-version: '8.4'
              extensions: {$ext}
              coverage: none

          - name: composer.json is valid
            run: composer validate --no-check-publish

          # Only the packages this one declares: one it uses without declaring
          # fails to load below.
          - name: Install with the declared packages only
            run: composer install --no-interaction --no-progress

          - name: Every PHP file parses
            run: find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

          - name: Every class loads
            run: |
              php -r '
                require "vendor/autoload.php";
                \$json = json_decode(file_get_contents("composer.json"), true);
                \$missing = [];
                foreach (\$json["autoload"]["psr-4"] as \$prefix => \$dir) {
                    \$base = rtrim(\$dir === "" ? "." : \$dir, "/");
                    \$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\$base, FilesystemIterator::SKIP_DOTS));
                    foreach (\$it as \$f) {
                        \$rel = substr(\$f->getPathname(), strlen(\$base) + 1);
                        if (\$f->getExtension() !== "php" || str_starts_with(\$rel, "vendor/") || str_contains("/" . \$rel, "/resources/") || basename(\$rel) === "constants.php") {
                            continue;
                        }
                        \$class = \$prefix . str_replace("/", "\\\\", substr(\$rel, 0, -4));
                        if (!class_exists(\$class) && !interface_exists(\$class) && !trait_exists(\$class) && !enum_exists(\$class)) {
                            \$missing[] = \$class;
                        }
                    }
                }
                if (\$missing !== []) { fwrite(STDERR, implode("\\n", \$missing) . "\\n"); exit(1); }
                echo "every class loads\\n";
              '

    YAML;

    return [
        'composer.json'            => json($json),
        '.github/workflows/ci.yml' => $ci,
    ];
}

// ── The Rust suite ───────────────────────────────────────────────────────────

/**
 * The suite's repository: its workflow, which lived at the monorepo's root
 * and ran inside astrx-suite/, now at its own root. The `boundary` job
 * guarded the line between the suite and the site inside one repository;
 * separate repositories are that line, so it goes.
 *
 * @return array<string, ?string>
 */
function suite(string $root): array
{
    $file = '.github/workflows/astrx-suite.yml';
    $yml  = (string) file_get_contents($root . '/' . $file);
    $yml  = edit($file, $yml, [
        ["  push:\n    paths: ['astrx-suite/**', '.github/workflows/astrx-suite.yml']\n  pull_request:\n    paths: ['astrx-suite/**', '.github/workflows/astrx-suite.yml']\n",
         "  push:\n  pull_request:\n"],
        ["defaults:\n  run:\n    working-directory: astrx-suite\n", "defaults:\n  run:\n    working-directory: .\n"],
    ]);
    // The boundary job: from its header comment to the next job's.
    $start = strpos($yml, "  # ------------------- the boundary between the two halves of the project\n");
    $end   = strpos($yml, "  # ------------------------------------------------------------- supply chain\n");
    if ($start === false || $end === false || $end < $start) {
        throw new \RuntimeException("{$file}: the boundary job's markers moved — update tools/lib/split_adapt.php");
    }
    $yml = substr($yml, 0, $start) . substr($yml, $end);
    $yml = str_replace(
        ['workspaces: astrx-suite', 'manifest-path: astrx-suite/Cargo.toml', 'file=astrx-suite/'],
        ['workspaces: .', 'manifest-path: Cargo.toml', 'file='],
        $yml,
    );
    if (str_contains($yml, 'astrx-suite/')) {
        // Prose may still say "astrx-suite/…"; a path the workflow uses may not.
        foreach (explode("\n", $yml) as $line) {
            $code = trim(explode('#', $line, 2)[0]);
            if ($code !== '' && str_contains($code, 'astrx-suite/')) {
                throw new \RuntimeException("{$file}: still uses a path under astrx-suite/: {$line} — update tools/lib/split_adapt.php");
            }
        }
    }
    return ['.github/workflows/ci.yml' => $yml];
}

// ── The site ─────────────────────────────────────────────────────────────────

/**
 * The site's repository: Composer installs the packages from their
 * repositories; the class loader looks under vendor/ only; the containers
 * mount vendor/ where they mounted packages/; the Rust suite's compose file is
 * included from a checkout next to the site (../astrx-suite); the suite's
 * workflow leaves with the suite; CI installs the packages (and fetches the
 * suite for the compose file) before it runs anything.
 *
 * @param array<string, mixed> $rootJson
 * @param array<string, array{dir: string, json: array<string, mixed>}> $packages
 * @param array<string, string> $generated src/… => content (tools/packages.php --installed)
 * @return array<string, ?string>
 */
function site(string $root, array $rootJson, array $packages, string $repoUrl, string $siteShort, array $generated): array
{
    $files = [];

    $json = $rootJson;
    $json['homepage']     = repo($repoUrl, $siteShort);
    $json['repositories'] = array_values(array_map(
        static fn(string $n): array => ['type' => 'vcs', 'url' => repo($repoUrl, short($n))],
        array_keys($packages),
    ));
    $files['composer.json'] = json($json);
    foreach ($generated as $rel => $content) {
        $files[$rel] = $content;
    }

    $f = 'phpstan.neon';
    $files[$f] = dropLines($f, (string) file_get_contents($root . '/' . $f), 'packages');

    $suiteUrl = repo($repoUrl, 'astrx-suite');
    $f = 'docker-compose.yml';
    $files[$f] = edit($f, (string) file_get_contents($root . '/' . $f), [
        ["include:\n  - path: astrx-suite/docker-compose.yml\n",
         "# The Rust suite is its own repository ({$suiteUrl}); clone it next to\n"
         . "# this one (../astrx-suite) for its services.\ninclude:\n  - path: ../astrx-suite/docker-compose.yml\n"],
    ]);
    $files[$f] = str_replace('./packages:/app/packages:ro', './vendor:/app/vendor:ro', $files[$f]);
    if (str_contains($files[$f], './packages')) {
        throw new \RuntimeException("{$f}: still mounts ./packages — update tools/lib/split_adapt.php");
    }

    $f = 'tools/ci-production-install.sh';
    $files[$f] = edit($f, (string) file_get_contents($root . '/' . $f), [
        ['"packages/framework/Kernel/Controller.php"', '"vendor/astrx/framework/Kernel/Controller.php"'],
    ]);

    $install = "\n      - name: Install the packages (Composer)\n"
        . "        run: composer install --no-interaction --no-progress\n";
    $f = '.github/workflows/ci.yml';
    $ci = (string) file_get_contents($root . '/' . $f);
    if (substr_count($ci, "          coverage: none\n") < 1) {
        throw new \RuntimeException("{$f}: no setup-php step found — update tools/lib/split_adapt.php");
    }
    $ci = str_replace("          coverage: none\n", "          coverage: none\n" . $install, $ci);
    $files[$f] = edit($f, $ci, [
        ["      # The code ships as Composer packages in packages/ (framework, library,\n"
         . "      # cms, one per module): each one names the packages it uses, and\n"
         . "      # src/sources.php (the class loader's map) is generated from them.\n"
         . "      - name: Packages (dependencies declared, class map current)\n",
         "      # The packages come from Composer (vendor/astrx/…): each one names the\n"
         . "      # packages it uses, and src/sources.php (the class loader's map) is\n"
         . "      # generated from them.\n"
         . "      - name: Packages (dependencies declared, class map current)\n"],
        ["          composer validate --no-check-publish\n"
         . "          for pkg in packages/*/composer.json packages/modules/*/composer.json; do\n"
         . "            composer validate --no-check-publish --working-dir=\"\$(dirname \"\$pkg\")\"\n"
         . "          done\n"
         . "          composer update --no-interaction --no-progress\n",
         "          composer validate --no-check-publish\n"],
    ]);

    $f = '.github/workflows/production-install.yml';
    $files[$f] = edit($f, (string) file_get_contents($root . '/' . $f), [
        ["      - uses: actions/checkout@v4\n",
         "      - uses: actions/checkout@v4\n"
         . "      - name: Set up PHP 8.4\n"
         . "        uses: shivammathur/setup-php@v2\n"
         . "        with:\n"
         . "          php-version: '8.4'\n"
         . "          coverage: none\n"
         . $install
         . "      # docker-compose.yml includes the Rust suite's compose file from a\n"
         . "      # checkout next to this one.\n"
         . "      - name: Fetch the Rust suite's compose file\n"
         . "        run: git clone --depth 1 {$suiteUrl} ../astrx-suite\n"],
    ]);

    // The suite's workflow leaves with the suite.
    $files['.github/workflows/astrx-suite.yml'] = null;

    return $files;
}
