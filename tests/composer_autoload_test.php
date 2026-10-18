<?php
declare(strict_types=1);

/**
 * Composer packaging contract.
 *
 *   1. PSR-4: every class-like declaration under src/AstrX lives at the path its
 *      fully-qualified name implies (AstrX\Foo\Bar → src/AstrX/Foo/Bar.php),
 *      one per file. The `"AstrX\\": "src/AstrX/"` mapping in composer.json —
 *      and every future per-package mapping — depends on it.
 *   2. When vendor/autoload.php exists (`composer dump-autoload`), Composer's
 *      autoloader resolves every one of those classes.
 *   3. Including vendor/autoload.php is inert: no output, no session, no
 *      headers, no application constants, nothing booted.
 *
 * Part 1 needs nothing; parts 2–3 are skipped with a note when vendor/ is absent.
 * Standalone: no bootstrap, no database.
 */

$root = dirname(__DIR__);
$PASS = 0;
$FAIL = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  ok   - {$label}\n";
    } else {
        $FAIL++;
        echo "  FAIL - {$label}" . ($detail !== '' ? "\n         {$detail}" : '') . "\n";
    }
}

// ── 1. PSR-4 layout ─────────────────────────────────────────────────────────
echo "1. PSR-4 layout\n";
/** @var list<string> $classes */
$classes = [];
$bad     = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src/AstrX', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $rel    = substr($file->getPathname(), strlen($root . '/src/'));
    $tokens = token_get_all((string) file_get_contents($file->getPathname()));
    $ns     = '';
    $decl   = [];
    $count  = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok)) {
            continue;
        }
        if ($tok[0] === T_NAMESPACE) {
            for ($j = $i + 1; $j < $count && is_array($tokens[$j]); $j++) {
                if (in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING], true)) {
                    $ns = $tokens[$j][1];
                    break;
                }
            }
        }
        if (in_array($tok[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            // skip `Foo::class` and `new class`
            $p = $i - 1;
            while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) { $p--; }
            if (is_array($tokens[$p] ?? null) && in_array($tokens[$p][0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $decl[] = ($ns !== '' ? $ns . '\\' : '') . $tokens[$j][1];
                    break;
                }
                if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                    break;
                }
            }
        }
    }
    if ($decl === []) {
        continue; // Support/constants.php (functions only)
    }
    $expected = str_replace('/', '\\', substr($rel, 0, -4));
    if (count($decl) !== 1 || $decl[0] !== $expected) {
        $bad[] = "{$rel} declares " . implode(', ', $decl);
    } else {
        $classes[] = $expected;
    }
}
check('found the class files (expected 400+)', count($classes) >= 400, (string) count($classes));
check('every file declares exactly the class its path implies', $bad === [], implode("\n         ", $bad));

// ── 2/3. Composer's autoloader ──────────────────────────────────────────────
$autoload = $root . '/vendor/autoload.php';
echo "\n2. Composer autoloader\n";
if (!is_file($autoload)) {
    echo "  skip - vendor/autoload.php absent (run `composer dump-autoload`)\n";
} else {
    $constantsBefore = get_defined_constants(true)['user'] ?? [];
    ob_start();
    require $autoload;
    $output = (string) ob_get_clean();

    $unresolved = array_values(array_filter(
        $classes,
        static fn(string $c): bool => !class_exists($c) && !interface_exists($c) && !trait_exists($c) && !enum_exists($c),
    ));
    check('every class resolves through Composer', $unresolved === [], implode(', ', array_slice($unresolved, 0, 10)));
    check('Support functions are loaded', function_exists('AstrX\\Support\\atomicWrite'));

    echo "\n3. Loading is inert\n";
    check('no output', $output === '', var_export(substr($output, 0, 80), true));
    check('no session started', session_status() !== PHP_SESSION_ACTIVE);
    check('no headers queued', headers_list() === []);
    check('no application constants defined',
        array_diff_key(get_defined_constants(true)['user'] ?? [], $constantsBefore) === []);
    check('nothing booted', !class_exists(AstrX\Prelude::class, false) || !isset($GLOBALS['cm']));
}

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
