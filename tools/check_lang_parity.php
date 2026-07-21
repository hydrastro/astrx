<?php
declare(strict_types=1);

/**
 * Language parity checker (zero dependencies).
 *
 * Compares every array language file under resources/lang/en (recursively,
 * including the Diagnostics/ subdirectory) against its resources/lang/it
 * counterpart and reports:
 *
 *   - FILE MISSING : a language file that exists in one locale but not the other
 *   - MISMATCH     : keys present in one locale's file but absent from the other
 *   - NOT AN ARRAY : a language file that does not `return` an array
 *
 * Files are paired by their locale-stripped relative path, so
 *   en/Comment.en.php            <-> it/Comment.it.php
 *   en/Diagnostics/news.en.php   <-> it/Diagnostics/news.it.php
 *
 * Only keys are compared; values may be plain strings or callables (the
 * Diagnostics catalogs). Requiring a catalog file is safe here because closure
 * parameter types are resolved lazily at call time — the closures are never
 * invoked, so no AstrX class needs to be autoloadable.
 *
 * Exit codes:
 *   0  full parity
 *   1  at least one file-level or key-level mismatch (use in CI)
 *   2  the language directories could not be found
 *
 * Usage:
 *   php tools/check_lang_parity.php
 */

const PARITY_LOCALE_A = 'en';
const PARITY_LOCALE_B = 'it';

$langRoot = dirname(__DIR__) . '/resources/lang';
$dirA     = $langRoot . '/' . PARITY_LOCALE_A;
$dirB     = $langRoot . '/' . PARITY_LOCALE_B;

if (!is_dir($dirA) || !is_dir($dirB)) {
    fwrite(STDERR, "Language directory not found:\n  {$dirA}\n  {$dirB}\n");
    exit(2);
}

/**
 * Map every *.php lang file under $dir to its locale-stripped relative key.
 * e.g. under en/:  'Comment' => '/abs/en/Comment.en.php'
 *                  'Diagnostics/news' => '/abs/en/Diagnostics/news.en.php'
 *
 * @return array<string,string>
 */
function parity_collect(string $dir, string $locale): array
{
    $out    = [];
    $suffix = '.' . $locale . '.php';
    $it     = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php')) {
            continue;
        }
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($dir))), '/');

        if (str_ends_with($rel, $suffix)) {
            $key = substr($rel, 0, -strlen($suffix));   // 'Comment', 'Diagnostics/news'
        } else {
            $key = substr($rel, 0, -4);                 // fallback: strip '.php'
        }
        $out[$key] = $path;
    }

    ksort($out);
    return $out;
}

/**
 * Load a lang file and return its top-level keys, or null if it is not an array.
 *
 * @return list<string>|null
 */
function parity_keys(string $path): ?array
{
    $data = require $path;
    if (!is_array($data)) {
        return null;
    }
    $keys = [];
    foreach ($data as $k => $_v) {
        $keys[] = (string) $k;
    }
    return $keys;
}

$filesA = parity_collect($dirA, PARITY_LOCALE_A);
$filesB = parity_collect($dirB, PARITY_LOCALE_B);

$allRel = array_keys($filesA + $filesB);
sort($allRel);

$problems = 0;
$report   = [];

foreach ($allRel as $rel) {
    $labelA = PARITY_LOCALE_A . '/' . $rel . '.' . PARITY_LOCALE_A . '.php';
    $labelB = PARITY_LOCALE_B . '/' . $rel . '.' . PARITY_LOCALE_B . '.php';

    $hasA = isset($filesA[$rel]);
    $hasB = isset($filesB[$rel]);

    if (!$hasA || !$hasB) {
        $problems++;
        $missingLocale = $hasA ? PARITY_LOCALE_B : PARITY_LOCALE_A;
        $presentLabel  = $hasA ? $labelA : $labelB;
        $report[]      = "FILE MISSING: {$presentLabel} has no {$missingLocale} counterpart";
        continue;
    }

    $keysA = parity_keys($filesA[$rel]);
    $keysB = parity_keys($filesB[$rel]);

    if ($keysA === null) { $problems++; $report[] = "NOT AN ARRAY: {$labelA}"; }
    if ($keysB === null) { $problems++; $report[] = "NOT AN ARRAY: {$labelB}"; }
    if ($keysA === null || $keysB === null) {
        continue;
    }

    $missingInB = array_values(array_diff($keysA, $keysB)); // in A, absent from B
    $missingInA = array_values(array_diff($keysB, $keysA)); // in B, absent from A

    if ($missingInB === [] && $missingInA === []) {
        continue;
    }

    $problems++;
    $lines = ["MISMATCH: {$rel}"];
    foreach ($missingInB as $key) {
        $lines[] = "  in {$labelA} but missing from {$labelB}: {$key}";
    }
    foreach ($missingInA as $key) {
        $lines[] = "  in {$labelB} but missing from {$labelA}: {$key}";
    }
    $report[] = implode("\n", $lines);
}

if ($problems === 0) {
    echo 'Language parity OK: ' . PARITY_LOCALE_A . ' and ' . PARITY_LOCALE_B
        . ' match across ' . count($allRel) . " file(s).\n";
    exit(0);
}

fwrite(STDERR, "Language parity check FAILED ({$problems} problem(s)):\n\n");
fwrite(STDERR, implode("\n\n", $report) . "\n");
exit(1);
