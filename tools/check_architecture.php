<?php
declare(strict_types=1);

/**
 * AstrX architecture gate — `php tools/check_architecture.php [--graph|--dot]`
 *
 * A zero-dependency, database-free CI gate that keeps the namespace dependency
 * graph layered and acyclic, so the planned package split stays possible.
 *
 * Every top-level namespace under src/AstrX (and every top-level class such as
 * AstrX\ContentManager) is assigned to exactly ONE layer in LAYERS below. The
 * tool tokenizes every PHP file, resolves the AstrX names each one references
 * (imports, fully-qualified names, namespace-relative qualified names,
 * attributes, ::class constants) and fails when:
 *
 *   1. a namespace depends on a namespace in a HIGHER layer
 *      (e.g. the template engine importing the accounts layer);
 *   2. the namespace graph has a cycle, even inside one layer
 *      (two features that need each other cannot ship as separate packages);
 *   3. a namespace exists that no layer claims (new code must pick a layer).
 *
 * Comments and docblocks are ignored: {@see} references are documentation, not
 * dependencies. String class names (e.g. 'AstrX\\Foo') are not detected; use
 * ::class so both this gate and PHPStan can see the reference.
 *
 * Options:
 *   --graph            print every namespace → namespace edge with its reference count
 *   --dot              print the graph in Graphviz DOT format (layers as clusters)
 *   --list=a,b         print the namespaces of the named layers, one per line
 *                      (tests/framework_isolation_test.php uses this so the
 *                      framework's file set has exactly one definition)
 *
 * Exit 0 when the graph is clean, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

/**
 * Layers, lowest first. A namespace may depend on its own layer or any lower
 * one, never a higher one; cycles are rejected everywhere. These mirror the
 * planned Composer packages: foundation + component = the framework,
 * library = optional building blocks, feature = optional application modules,
 * application = this website's composition (to become the site repository).
 *
 * @var array<string, list<string>>
 */
const LAYERS = [
    'foundation'  => ['Result', 'Support'],
    'component'   => [
        'Config', 'Injector', 'Http', 'Routing', 'ErrorHandler', 'I18n',
        'Template', 'Session', 'Csrf', 'Module', 'Pagination', 'Kernel',
    ],
    'library'     => ['Database', 'Image', 'Auth', 'Api', 'Captcha', 'Identicon', 'Mail'],
    'feature'     => [
        'User', 'Admin', 'Page', 'Content', 'Navbar', 'Theme', 'Comment', 'News',
        'Chat', 'Imageboard', 'Media', 'Search', 'Invite', 'BotTrap', 'Canary',
        'Downloads', 'Mirrors', 'Tipline', 'Retention', 'Webmail', 'Blocklist',
        'GitBrowse', 'WebSearch', 'OnionSearch', 'TorrentSearch',
        'FederatedSearch', 'SuiteAdmin',
    ],
    'application' => ['Site', 'ContentManager', 'Prelude', 'ApplicationPolicies', 'ApplicationPermissions', 'ApplicationModules'],
];

// --list=layer[,layer]: print members and stop (no scan needed).
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--list=')) {
        foreach (explode(',', substr($arg, 7)) as $layer) {
            if (!isset(LAYERS[$layer])) {
                fwrite(STDERR, "unknown layer '{$layer}'\n");
                exit(1);
            }
            foreach (LAYERS[$layer] as $ns) {
                fwrite(STDOUT, $ns . "\n");
            }
        }
        exit(0);
    }
}

$root     = dirname(__DIR__);
$classDir = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'AstrX';
$mode     = in_array('--dot', $argv, true) ? 'dot' : (in_array('--graph', $argv, true) ? 'graph' : 'check');

/** @var array<string,int> $rank namespace => layer index */
$rank = [];
/** @var array<string,string> $layerOf namespace => layer name */
$layerOf = [];
$i = 0;
foreach (LAYERS as $layer => $members) {
    foreach ($members as $ns) {
        $rank[$ns]    = $i;
        $layerOf[$ns] = $layer;
    }
    $i++;
}

/**
 * Collect the AstrX names one file references.
 *
 * @return array{owner: ?string, refs: list<array{name: string, line: int}>}
 */
function scanFile(string $code): array
{
    $tokens  = token_get_all($code);
    $count   = count($tokens);
    $ns      = '';
    $owner   = null;
    /** @var array<string,string> $aliases alias => fully-qualified name */
    $aliases = [];
    $refs    = [];
    $depth   = 0;

    for ($k = 0; $k < $count; $k++) {
        $tok = $tokens[$k];
        if (is_string($tok)) {
            if ($tok === '{') { $depth++; }
            if ($tok === '}') { $depth--; }
            continue;
        }
        [$id, $text, $line] = $tok;
        if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
            continue;
        }

        if ($id === T_NAMESPACE) {
            // `namespace Foo\Bar;` (a declaration) vs `namespace\Foo` (relative name).
            $k++;
            while ($k < $count && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) { $k++; }
            $next = $tokens[$k] ?? null;
            if (is_array($next) && in_array($next[0], [T_NAME_QUALIFIED, T_STRING], true)) {
                $ns      = $next[1];
                $aliases = [];
                $parts   = explode('\\', $ns);
                if ($parts[0] === 'AstrX' && isset($parts[1])) {
                    $owner = $parts[1];
                }
            }
            continue;
        }

        // File-level imports (not closure `use (...)`, not trait `use` inside a class).
        if ($id === T_USE && $depth === 0) {
            $k = parseUse($tokens, $k, $aliases, $refs);
            continue;
        }

        if ($id === T_NAME_FULLY_QUALIFIED) {
            $refs[] = ['name' => ltrim($text, '\\'), 'line' => $line];
        } elseif ($id === T_NAME_QUALIFIED) {
            $first = explode('\\', $text)[0];
            $refs[] = [
                'name' => isset($aliases[$first])
                    ? $aliases[$first] . substr($text, strlen($first))
                    : ($ns !== '' ? $ns . '\\' . $text : $text),
                'line' => $line,
            ];
        }
    }

    return ['owner' => $owner, 'refs' => $refs];
}

/**
 * Parse one file-level `use` statement starting at $k; returns the index of
 * its terminating `;`. Records every imported name as a reference.
 *
 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
 * @param array<string,string>                            $aliases
 * @param list<array{name:string,line:int}>               $refs
 */
function parseUse(array $tokens, int $k, array &$aliases, array &$refs): int
{
    $count  = count($tokens);
    $prefix = '';
    $name   = '';
    $line   = 0;
    $alias  = null;
    $inAs   = false;

    for ($k++; $k < $count; $k++) {
        $tok = $tokens[$k];
        if (is_string($tok)) {
            if ($tok === ';') { flushUse($prefix, $name, $alias, $line, $aliases, $refs); return $k; }
            if ($tok === ',') { flushUse($prefix, $name, $alias, $line, $aliases, $refs); $inAs = false; continue; }
            if ($tok === '{') { $prefix = $name; $name = ''; continue; }
            if ($tok === '}') { flushUse($prefix, $name, $alias, $line, $aliases, $refs); continue; }
            continue;
        }
        [$id, $text, $tline] = $tok;
        if ($id === T_AS) { $inAs = true; continue; }
        if ($id === T_FUNCTION || $id === T_CONST || $id === T_WHITESPACE
            || $id === T_COMMENT || $id === T_DOC_COMMENT) {
            continue;
        }
        if ($id === T_NS_SEPARATOR) { $name .= '\\'; continue; }
        if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
            if ($inAs) {
                $alias = $text;
                $inAs  = false;
            } else {
                $name .= $text;
                $line  = $tline;
            }
        }
    }
    return $k;
}

/**
 * Record the import collected so far (if any) and reset the per-name state.
 *
 * @param array<string,string>              $aliases
 * @param list<array{name:string,line:int}> $refs
 */
function flushUse(string $prefix, string &$name, ?string &$alias, int $line, array &$aliases, array &$refs): void
{
    if ($name === '') {
        return;
    }
    $full   = ltrim($prefix . $name, '\\');
    $refs[] = ['name' => $full, 'line' => $line];
    $parts  = explode('\\', $full);
    $aliases[$alias ?? $parts[count($parts) - 1]] = $full;
    $name   = '';
    $alias  = null;
}

/** Map an AstrX FQCN to its graph node (top-level namespace or top-level class). */
function nodeOf(string $fqcn): ?string
{
    $parts = explode('\\', $fqcn);
    if ($parts[0] !== 'AstrX' || !isset($parts[1]) || $parts[1] === '') {
        return null;
    }
    return $parts[1];
}

// ── Scan ─────────────────────────────────────────────────────────────────────
/** @var array<string, array<string, list<string>>> $edges from => to => list of "file:line" */
$edges = [];
/** @var array<string,true> $nodes */
$nodes = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($classDir, FilesystemIterator::SKIP_DOTS));
/** @var SplFileInfo $file */
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    // A module's resources/ (catalogs, default config) is data, not code.
    if (str_contains($path, DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $rel  = substr($path, strlen($root) + 1);
    $code = file_get_contents($path);
    if ($code === false) {
        continue;
    }
    $scan  = scanFile($code);
    $owner = $scan['owner'];
    if ($owner === null) {
        // `namespace AstrX;` (top-level class, node = class name) or no namespace
        // at all (Support/constants.php) — attribute by directory.
        $sub   = substr($path, strlen($classDir) + 1);
        $owner = str_contains($sub, DIRECTORY_SEPARATOR)
            ? explode(DIRECTORY_SEPARATOR, $sub)[0]
            : basename($sub, '.php');
    }
    $nodes[$owner] = true;
    foreach ($scan['refs'] as $ref) {
        $to = nodeOf($ref['name']);
        if ($to === null || $to === $owner) {
            continue;
        }
        $edges[$owner][$to][] = $rel . ':' . $ref['line'];
    }
}
ksort($nodes);
ksort($edges);

// ── Output modes ─────────────────────────────────────────────────────────────
if ($mode === 'graph') {
    foreach ($edges as $from => $tos) {
        ksort($tos);
        foreach ($tos as $to => $where) {
            fwrite(STDOUT, sprintf("%-20s -> %-20s %d\n", $from, $to, count($where)));
        }
    }
    exit(0);
}
if ($mode === 'dot') {
    fwrite(STDOUT, "digraph astrx {\n  rankdir=BT;\n  node [shape=box, fontname=monospace];\n");
    foreach (LAYERS as $layer => $members) {
        fwrite(STDOUT, "  subgraph cluster_{$layer} {\n    label=\"{$layer}\";\n");
        foreach ($members as $ns) {
            fwrite(STDOUT, "    \"{$ns}\";\n");
        }
        fwrite(STDOUT, "  }\n");
    }
    foreach ($edges as $from => $tos) {
        foreach (array_keys($tos) as $to) {
            fwrite(STDOUT, "  \"{$from}\" -> \"{$to}\";\n");
        }
    }
    fwrite(STDOUT, "}\n");
    exit(0);
}

// ── Checks ───────────────────────────────────────────────────────────────────
/** @var list<string> $errors */
$errors = [];

foreach (array_keys($nodes) as $ns) {
    if (!isset($rank[$ns])) {
        $errors[] = "namespace AstrX\\{$ns} is not assigned to a layer — add it to LAYERS in tools/check_architecture.php";
    }
}
foreach (array_keys($rank) as $ns) {
    if (!isset($nodes[$ns])) {
        $errors[] = "LAYERS lists AstrX\\{$ns}, which no longer exists — remove it";
    }
}

foreach ($edges as $from => $tos) {
    foreach ($tos as $to => $where) {
        if (!isset($rank[$from], $rank[$to])) {
            continue; // reported above
        }
        if ($rank[$to] > $rank[$from]) {
            $sample = implode(', ', array_slice(array_values(array_unique($where)), 0, 3));
            $errors[] = sprintf(
                '%s (%s) must not depend on %s (%s): %s%s',
                $from, $layerOf[$from], $to, $layerOf[$to], $sample,
                count($where) > 3 ? ' …' : '',
            );
        }
    }
}

// Tarjan's strongly connected components: any component of size > 1 is a cycle.
$index = 0;
/** @var array<string,int> $idx */
$idx = [];
/** @var array<string,int> $low */
$low = [];
/** @var list<string> $stack */
$stack = [];
/** @var array<string,true> $onStack */
$onStack = [];
/** @var list<list<string>> $cycles */
$cycles = [];
$strong = static function (string $v) use (&$strong, &$index, &$idx, &$low, &$stack, &$onStack, &$cycles, $edges): void {
    $idx[$v] = $low[$v] = $index++;
    $stack[] = $v;
    $onStack[$v] = true;
    foreach (array_keys($edges[$v] ?? []) as $w) {
        if (!isset($idx[$w])) {
            $strong($w);
            $low[$v] = min($low[$v], $low[$w]);
        } elseif (isset($onStack[$w])) {
            $low[$v] = min($low[$v], $idx[$w]);
        }
    }
    if ($low[$v] === $idx[$v]) {
        $component = [];
        while (($w = array_pop($stack)) !== null) {
            unset($onStack[$w]);
            $component[] = $w;
            if ($w === $v) {
                break;
            }
        }
        if (count($component) > 1) {
            sort($component);
            $cycles[] = $component;
        }
    }
};
foreach (array_keys($nodes) as $v) {
    if (!isset($idx[$v])) {
        $strong($v);
    }
}
foreach ($cycles as $component) {
    $members = array_flip($component);
    $detail  = [];
    foreach ($component as $from) {
        foreach (array_keys($edges[$from] ?? []) as $to) {
            if (isset($members[$to])) {
                $detail[] = "{$from} -> {$to} (" . ($edges[$from][$to][0] ?? '?') . ')';
            }
        }
    }
    $errors[] = 'dependency cycle between ' . implode(', ', $component) . ":\n      " . implode("\n      ", $detail);
}

// ── Report ───────────────────────────────────────────────────────────────────
fwrite(STDOUT, "AstrX architecture\n==================\n\n");
foreach (LAYERS as $layer => $members) {
    fwrite(STDOUT, sprintf("  %-12s %s\n", $layer, implode(', ', $members)));
}
fwrite(STDOUT, "\n");

if ($errors !== []) {
    foreach ($errors as $e) {
        fwrite(STDERR, "  ERROR: {$e}\n");
    }
    fwrite(STDERR, "\n" . count($errors) . " error(s) — architecture check FAILED.\n");
    exit(1);
}

$edgeCount = 0;
foreach ($edges as $tos) {
    $edgeCount += count($tos);
}
fwrite(STDOUT, count($nodes) . " namespaces, {$edgeCount} edges: layered and acyclic.\n");
exit(0);
