<?php
declare(strict_types=1);

use AstrX\Config\ConfigDomain;
use AstrX\Config\ConfigDomainResolver;
use AstrX\Config\InjectConfig;

/**
 * AstrX config integrity check — `php tools/check_config.php`
 *
 * A zero-dependency, database-free CI gate over the config layer. Config in
 * AstrX is a two-level array: a FILE (`resources/config/Mail.config.php`) holds
 * one or more SECTIONS (`Mailer`, `ImapClient`, `WebmailService`), and a class
 * reaches its section by convention — class short name, falling back to the
 * parent namespace segment — or by an explicit `#[ConfigDomain]`. 25 of the 38
 * sections have a name that differs from their file, and nothing used to check
 * that anyone actually agreed on the mapping. The webmail admin page read
 * section `ImapClient` (Mail.config.php) and wrote `Imap.config.php`, a file no
 * code path loads: every IMAP setting, including the SOCKS5 host/port that
 * routes IMAP through Tor, was silently write-only.
 *
 * Four assertions:
 *
 *   A. REACHABLE   — every declared section is reached by some class (by
 *                    #[ConfigDomain] or by the short-name/parent-namespace
 *                    convention) or by a getConfig*() call site. A section
 *                    nobody reaches is config an operator can edit forever with
 *                    no effect.
 *   B. SETTER KEY  — every #[InjectConfig('k')] names a key that exists in the
 *                    class's section. A typo'd or removed key means the setter
 *                    silently never fires and the hardcoded default stands.
 *   C. CONSUMED    — every key in a section has a consumer: an #[InjectConfig]
 *                    setter on a class in that section, or a getConfig*() call
 *                    site naming it. A key with no consumer is an admin lever
 *                    wired to nothing.
 *   D. WRITE TARGET— every ConfigWriter::write('<File>', …) call site names a
 *                    config file that exists, and every section it writes is
 *                    declared in that same file. This is the one that catches
 *                    "saved successfully" into a file nothing loads.
 *
 * Exit 0 when everything holds; exit 1 on any violation.
 * Pairs with PHPStan (types), check_lang_parity.php + check_diagnostics.php
 * (i18n) and check_modules.php (manifests).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

$root = dirname(__DIR__);

require_once $root . '/src/paths.php';
// Classes and the Support functions (the config files call configDir()).
require_once $root . '/src/autoload.php';
require_once $root . '/tools/lib/sources.php';

/** @var array<string,string> $errors stable key => message */
$errors = [];
/** @var list<string> $notes */
$notes = [];

// ─────────────────────────────────────────────────────────────────────────────
// 1. The declared layout: section => [file, keys]
// ─────────────────────────────────────────────────────────────────────────────

/** @var array<string,array{file:string,keys:list<string>}> $sections */
$sections = [];
/** @var array<string,true> $configFileNames base names that exist on disk */
$configFileNames = [];

// The site's config files, then every installed module's config/ defaults. A
// module's file and the site's file of the same name are ONE file as far as the
// loader is concerned (the site's keys override the module's), so a section
// declared in both is not a duplicate: its keys are the union.
$roots = AstrX\ApplicationContainer::resourceRoots();
$configFiles = [];
foreach ([CONFIG_DIR, ...$roots->of(AstrX\Support\ResourceRoots::CONFIG)] as $dir) {
    foreach (ConfigDomainResolver::configFiles($dir) as $base => $path) {
        $configFiles[] = [$base, $path];
    }
}

foreach ($configFiles as [$base, $path]) {
    $configFileNames[$base] = true;
    /** @var mixed $loaded */
    $loaded = require $path;
    if (!is_array($loaded)) {
        $errors["LAYOUT:{$base}"] = "LAYOUT: {$base}.config.php does not return an array";
        continue;
    }
    /** @var mixed $keys */
    foreach ($loaded as $section => $keys) {
        if (!is_string($section)) {
            $errors["LAYOUT:{$base}:nonstring"] = "LAYOUT: {$base} declares a non-string section key";
            continue;
        }
        if (isset($sections[$section]) && $sections[$section]['file'] !== $base) {
            $errors["LAYOUT:dup:{$section}"] = "LAYOUT: section '{$section}' is declared twice — in "
                . $sections[$section]['file'] . " and in {$base}";
            continue;
        }
        $keyNames = $sections[$section]['keys'] ?? [];
        if (is_array($keys)) {
            foreach (array_keys($keys) as $k) {
                if (!in_array((string) $k, $keyNames, true)) {
                    $keyNames[] = (string) $k;
                }
            }
        }
        $sections[$section] = ['file' => $base, 'keys' => $keyNames];
    }
}

if ($sections === []) {
    fwrite(STDERR, "No config sections found under " . CONFIG_DIR . " — nothing to check.\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. The consumers: classes, their #[InjectConfig] setters, getConfig call sites
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Every *.php file under the given roots.
 *
 * @param  list<string> $roots
 * @return list<string>
 */
function cfg_php_files(array $roots): array
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

$resolver = new ConfigDomainResolver(CONFIG_DIR, $roots);

/** @var array<string,true> $reached sections some class resolves to */
$reached = [];
/** @var array<string,array<string,true>> $setterKeys section => key => true */
$setterKeys = [];

foreach (array_keys(AstrX\Tools\classFiles($root)) as $fqcn) {
    if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn) && !enum_exists($fqcn)) {
        // Not loadable in isolation (e.g. it needs a constant the bootstrap
        // defines). Reachability is still established from the name.
        foreach ($resolver->forClass($fqcn) as $candidate) {
            if (isset($sections[$candidate['section']])) { $reached[$candidate['section']] = true; }
        }
        continue;
    }

    if (!class_exists($fqcn)) {
        continue; // interface / trait / enum-only file: no config of its own
    }
    $rc = new ReflectionClass($fqcn);

    // A section is "reached" when a class resolves to it, whether or not that
    // class has #[InjectConfig] setters — plenty read theirs via getConfig().
    $candidates = [];
    foreach ($rc->getAttributes(ConfigDomain::class) as $attr) {
        /** @var ConfigDomain $domain */
        $domain = $attr->newInstance();
        $candidates[] = ['section' => $domain->section, 'file' => $domain->fileBaseName()];
        // D (declaration side): the attribute must agree with the on-disk layout.
        $declaredIn = $sections[$domain->section]['file'] ?? null;
        if ($declaredIn !== null && $declaredIn !== $domain->fileBaseName()) {
            $errors["DOMAIN:{$fqcn}:{$domain->section}"] = "DOMAIN: {$fqcn} declares section '{$domain->section}' in "
                . "'{$domain->fileBaseName()}.config.php', but it is declared in '{$declaredIn}.config.php'";
        }
    }
    if ($candidates === []) {
        $candidates = $resolver->forClass($fqcn);
    }
    foreach ($candidates as $candidate) {
        if (isset($sections[$candidate['section']])) { $reached[$candidate['section']] = true; }
    }

    // B: every #[InjectConfig] key must exist in one of the class's sections.
    foreach ($rc->getMethods() as $method) {
        foreach ($method->getAttributes(InjectConfig::class) as $attr) {
            /** @var InjectConfig $inject */
            $inject  = $attr->newInstance();
            $key     = $inject->key;
            $matched = null;
            foreach ($candidates as $candidate) {
                $section = $candidate['section'];
                if (isset($sections[$section]) && in_array($key, $sections[$section]['keys'], true)) {
                    $matched = $section;
                    break;
                }
            }
            if ($matched === null) {
                $names = implode(' | ', array_column($candidates, 'section'));
                $errors["SETTER:{$fqcn}::{$method->getName()}"] =
                    "SETTER: {$fqcn}::{$method->getName()}() injects '{$key}', "
                    . "absent from every section it resolves to ({$names})";
                continue;
            }
            $setterKeys[$matched][$key] = true;
        }
    }
}

// getConfig*('Section', 'key') / getConfigSection('Section') call sites.
// Whitespace-tolerant: the admin controllers wrap these across several lines.
/** @var array<string,array<string,array<string,true>>> $readKeys section => key => file => true */
$readKeys = [];
/** @var array<string,true> $readSections */
$readSections = [];
/** @var array<string,true> $dynamicSections read with a non-literal key */
$dynamicSections = [];
/** @var list<array{file:string,line:int,target:string,sections:list<string>}> $writeSites */
$writeSites = [];
/** @var array<string,array<string,true>> $writesSection file => section => true */
$writesSection = [];

$scanRoots = [...AstrX\Tools\sourceDirs($root), INDEX_DIR . 'public', INDEX_DIR . 'tools'];
foreach (cfg_php_files($scanRoots) as $path) {
    if (str_ends_with($path, DIRECTORY_SEPARATOR . 'check_config.php')) { continue; }
    $src = (string) file_get_contents($path);

    // Literal-key reads.
    if (preg_match_all(
        "/getConfig(?:String|Int|Bool|Array)?\s*\(\s*'([A-Za-z0-9_]+)'\s*,\s*'([A-Za-z0-9_.]+)'/",
        $src,
        $m,
        PREG_SET_ORDER,
    ) > 0) {
        foreach ($m as $hit) {
            $readSections[$hit[1]] = true;
            $readKeys[$hit[1]][$hit[2]][substr($path, strlen($root) + 1)] = true;
        }
    }

    // Section-level and variable-key reads. `getConfigBool('Modules', $key)`
    // consumes every key in the section, so the section is exempt from C.
    //
    // The whitespace after the comma is matched POSSESSIVELY (\s*+). With a
    // backtracking \s* the engine happily matched zero characters and then
    // found a newline rather than a quote, so every multi-line
    //     getConfig(
    //         'Translator',
    //         'lang_dir',
    // read — which is how the admin controllers are formatted — was classified
    // as a variable-key read and exempted its whole section from check C.
    if (preg_match_all(
        "/getConfig(?:String|Int|Bool|Array|Section)?\s*\(\s*'([A-Za-z0-9_]+)'\s*+(?:,\s*+(?!')|\))/",
        $src,
        $m2,
        PREG_SET_ORDER,
    ) > 0) {
        foreach ($m2 as $hit) {
            $readSections[$hit[1]]     = true;
            $dynamicSections[$hit[1]]  = true;
        }
    }

    // D: ConfigWriter::write('<File>', [ '<Section>' => … ]) call sites.
    if (preg_match_all(
        "/->write\s*\(\s*'([A-Za-z0-9_]+)'\s*,(.{0,4000}?)\)\s*;/s",
        $src,
        $m3,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    ) > 0) {
        foreach ($m3 as $hit) {
            $line = substr_count(substr($src, 0, $hit[0][1]), "\n") + 1;
            $names  = [];
            if (preg_match_all("/'([A-Za-z0-9_]+)'\s*=>\s*\[/", (string) $hit[2][0], $m4) > 0) {
                foreach ($m4[1] as $n) { $names[] = $n; }
            }
            $rel = substr($path, strlen($root) + 1);
            foreach ($names as $n) { $writesSection[$rel][$n] = true; }
            $writeSites[] = [
                'file'     => $rel,
                'line'     => $line,
                'target'   => (string) $hit[1][0],
                'sections' => $names,
            ];
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. Assertions
// ─────────────────────────────────────────────────────────────────────────────

// A. Every declared section is reachable.
foreach ($sections as $section => $meta) {
    if (isset($reached[$section]) || isset($readSections[$section])) {
        continue;
    }
    $errors["REACHABLE:{$section}"] =
        "REACHABLE: section '{$section}' ({$meta['file']}.config.php) is reached by no class "
        . 'and read by no getConfig() call site — editing it does nothing';
}

// C. Every key has a consumer.
foreach ($sections as $section => $meta) {
    if (isset($dynamicSections[$section])) {
        // Read with a computed key (module flags, permission grants): every key
        // in the section is a potential consumer target.
        continue;
    }
    foreach ($meta['keys'] as $key) {
        if (isset($setterKeys[$section][$key])) {
            continue;
        }

        // A read only counts as a consumer when it comes from a file that does
        // NOT also write the section. An admin editor reading back the value it
        // just wrote, to re-render its own form, is a closed loop: the setting
        // is validated, persisted and displayed, and still reaches no behaviour.
        // Translator.fallback_to_key and Translator.lang_dir are exactly that.
        $realReader = false;
        foreach (array_keys($readKeys[$section][$key] ?? []) as $file) {
            if (!isset($writesSection[$file][$section])) { $realReader = true; break; }
        }
        if ($realReader) {
            continue;
        }

        $roundTrip = isset($readKeys[$section][$key]);
        $errors["CONSUMED:{$meta['file']}/{$section}/{$key}"] =
            "CONSUMED: {$meta['file']}.config.php ['{$section}']['{$key}'] "
            . ($roundTrip
                ? 'is only read by the admin editor that writes it — validated, saved, '
                    . 'displayed back, and read by nothing that acts on it'
                : 'has no #[InjectConfig] setter and no getConfig() call site — '
                    . 'it is an admin lever wired to nothing');
    }
}

// D. Every write target is a file the loader reads, holding the written section.
foreach ($writeSites as $site) {
    if (!isset($configFileNames[$site['target']])) {
        $errors["WRITE:{$site['file']}:{$site['line']}"] =
            "WRITE TARGET: {$site['file']}:{$site['line']} persists to "
            . "'{$site['target']}.config.php', which no config directory (the site's or a module's) declares — "
            . 'nothing loads it, so the save is a no-op';
        continue;
    }
    foreach ($site['sections'] as $section) {
        $declaredIn = $sections[$section]['file'] ?? null;
        if ($declaredIn !== null && $declaredIn !== $site['target']) {
            $errors["WRITE:{$site['file']}:{$site['line']}:{$section}"] =
                "WRITE TARGET: {$site['file']}:{$site['line']} writes section '{$section}' into "
                . "'{$site['target']}.config.php', but the loader reads it from '{$declaredIn}.config.php'";
        }
    }
}

// Informational: reads of a section or key nothing declares. These fall back
// silently at runtime (Config::getConfig returns the caller's default before it
// emits anything), so they are worth seeing even though a missing OPTIONAL
// section is a legitimate pattern.
foreach ($readSections as $section => $_) {
    if (!isset($sections[$section])) {
        $notes[] = "read-but-undeclared section '{$section}' — every read falls back to its inline default";
    }
}
foreach ($readKeys as $section => $keys) {
    if (!isset($sections[$section])) { continue; }
    foreach (array_keys($keys) as $key) {
        if (!in_array($key, $sections[$section]['keys'], true)) {
            $notes[] = "read-but-undeclared key '{$section}.{$key}' — falls back to its inline default";
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. Report
// ─────────────────────────────────────────────────────────────────────────────

fwrite(STDOUT, "AstrX config integrity\n======================\n\n");
fwrite(STDOUT, sprintf(
    "  %d section(s) in %d file(s), %d write site(s)\n\n",
    count($sections),
    count($configFileNames),
    count($writeSites),
));

sort($notes);
foreach (array_unique($notes) as $n) {
    fwrite(STDOUT, "  note: {$n}\n");
}
if ($notes !== []) { fwrite(STDOUT, "\n"); }

foreach ($errors as $e) {
    fwrite(STDERR, "  ERROR: {$e}\n");
}

if ($errors !== []) {
    fwrite(STDERR, "\n" . count($errors) . " error(s) — config integrity check FAILED.\n");
    exit(1);
}

fwrite(STDOUT, "Config integrity OK\n");
exit(0);
