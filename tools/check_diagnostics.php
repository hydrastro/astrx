<?php
declare(strict_types=1);

/**
 * AstrX diagnostic catalog check — `php tools/check_diagnostics.php`
 *
 * Every diagnostic AstrX emits carries a stable id ('astrx.i18n/lang_write_failed').
 * DiagnosticRenderer turns that id into a sentence via a per-locale catalog under
 * resources/lang/{locale}/Diagnostics/. With no entry it falls back to a stamp —
 *
 *     [FALLBACK:ERROR] astrx.i18n/lang_write_failed
 *
 * — and DefaultTemplateContext renders that literal string into the page. 28 ids
 * had no entry in EITHER locale, so whole subsystems (the language editor, the
 * search indexer, the media library, the invite system) showed their internal
 * identifiers to users instead of a message.
 *
 * check_lang_parity.php cannot see this: it compares en against it, and a
 * message missing from BOTH locales is perfectly symmetric.
 *
 * What this checks: every diagnostic id that appears as a string literal in
 * src/, public/ or tools/ has a catalog entry in EVERY installed locale.
 *
 * Dynamic ids — `'astrx.imageboard/' . $slug` — cannot be enumerated statically.
 * Their prefix is recorded and every catalog entry under that prefix is treated
 * as reachable, so the check neither misses a static id nor invents failures for
 * a computed one. The prefixes it found are printed, so a subsystem that goes
 * fully dynamic is visible rather than silently unchecked.
 *
 * Exit 0 when every emitted id resolves in every locale; exit 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

$root     = dirname(__DIR__);
$langRoot = $root . '/resources/lang';

if (!is_dir($langRoot)) {
    fwrite(STDERR, "Language directory not found: {$langRoot}\n");
    exit(2);
}

/**
 * Every *.php file under the given roots.
 *
 * @param  list<string> $roots
 * @return list<string>
 */
function diag_php_files(array $roots): array
{
    $out = [];
    foreach ($roots as $dir) {
        if (!is_dir($dir)) { continue; }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) { continue; }
            $path = $file->getPathname();
            if (str_ends_with($path, '.php')) { $out[] = $path; }
        }
    }
    sort($out);
    return $out;
}

// ── 1. Emitted ids ───────────────────────────────────────────────────────────

/** @var array<string,string> $emitted id => "file:line" of the first sighting */
$emitted = [];
/** @var array<string,string> $dynamicPrefixes 'astrx.imageboard/' => first sighting */
$dynamicPrefixes = [];

foreach (diag_php_files([$root . '/src', $root . '/public', $root . '/tools']) as $path) {
    if (str_ends_with($path, DIRECTORY_SEPARATOR . 'check_diagnostics.php')) { continue; }
    $src = (string) file_get_contents($path);
    $rel = substr($path, strlen($root) + 1);

    // A concatenation ("'astrx.x/' . $slug") is a family, not an id.
    if (preg_match_all("/'(astrx\.[a-z0-9_]+\/)'\s*\./", $src, $dyn, PREG_SET_ORDER) > 0) {
        foreach ($dyn as $hit) {
            $dynamicPrefixes[$hit[1]] ??= $rel;
        }
    }

    if (preg_match_all(
        "/'(astrx\.[a-z0-9_]+\/[a-z0-9_.]+)'/",
        $src,
        $m,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    ) > 0) {
        foreach ($m as $hit) {
            $id = (string) $hit[1][0];
            if (isset($emitted[$id])) { continue; }
            $line = substr_count(substr($src, 0, $hit[0][1]), "\n") + 1;
            $emitted[$id] = "{$rel}:{$line}";
        }
    }
}

ksort($emitted);
ksort($dynamicPrefixes);

// ── 2. Catalog entries per locale ────────────────────────────────────────────

/** @var list<string> $locales */
$locales = [];
foreach (scandir($langRoot) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') { continue; }
    if (is_dir($langRoot . '/' . $entry)) { $locales[] = $entry; }
}
sort($locales);

if ($locales === []) {
    fwrite(STDERR, "No locales found under {$langRoot}\n");
    exit(2);
}

/** @var array<string,array<string,true>> $catalog locale => id => true */
$catalog = [];
foreach ($locales as $locale) {
    $catalog[$locale] = [];
    $dir = $langRoot . '/' . $locale . '/Diagnostics';
    if (!is_dir($dir)) { continue; }
    foreach (diag_php_files([$dir]) as $file) {
        // Parse rather than require: a catalog entry is a closure whose keys are
        // all we need, and requiring one twice (en and it declare the same
        // `use` aliases) is fine but pointlessly expensive.
        $src = (string) file_get_contents($file);
        if (preg_match_all("/'(astrx\.[a-z0-9_]+\/[a-z0-9_.]+)'\s*=>/", $src, $m) > 0) {
            foreach ($m[1] as $id) { $catalog[$locale][$id] = true; }
        }
    }
}

// ── 3. Assertions ────────────────────────────────────────────────────────────

/** @var list<string> $errors */
$errors = [];

foreach ($emitted as $id => $where) {
    foreach ($locales as $locale) {
        if (isset($catalog[$locale][$id])) { continue; }
        $errors[] = "MISSING [{$locale}]: '{$id}' (emitted at {$where}) has no catalog entry — "
            . "DiagnosticRenderer renders the literal \"[FALLBACK:…] {$id}\" into the page";
    }
}

// A catalog entry for an id nothing emits is dead weight; report it unless it
// belongs to a dynamically-built family.
/** @var list<string> $notes */
$notes = [];
foreach ($locales as $locale) {
    foreach (array_keys($catalog[$locale]) as $id) {
        if (isset($emitted[$id])) { continue; }
        $dynamic = false;
        foreach (array_keys($dynamicPrefixes) as $prefix) {
            if (str_starts_with($id, $prefix)) { $dynamic = true; break; }
        }
        if (!$dynamic) {
            $notes[] = "[{$locale}] '{$id}' has a catalog entry but nothing emits it";
        }
    }
}

// ── 4. Report ────────────────────────────────────────────────────────────────

fwrite(STDOUT, "AstrX diagnostic catalog\n========================\n\n");
fwrite(STDOUT, sprintf(
    "  %d emitted id(s), %d dynamic famil(y|ies), %d locale(s): %s\n\n",
    count($emitted),
    count($dynamicPrefixes),
    count($locales),
    implode(', ', $locales),
));

foreach ($dynamicPrefixes as $prefix => $where) {
    fwrite(STDOUT, "  dynamic: {$prefix}* built at {$where} — ids under it are not enumerable\n");
}
if ($dynamicPrefixes !== []) { fwrite(STDOUT, "\n"); }

sort($notes);
foreach ($notes as $n) {
    fwrite(STDOUT, "  note: {$n}\n");
}
if ($notes !== []) { fwrite(STDOUT, "\n"); }

foreach ($errors as $e) {
    fwrite(STDERR, "  ERROR: {$e}\n");
}

if ($errors !== []) {
    fwrite(STDERR, "\n" . count($errors) . " missing catalog entr(y|ies) — check FAILED.\n");
    exit(1);
}

fwrite(STDOUT, "Every emitted diagnostic id resolves in every locale.\n");
exit(0);
