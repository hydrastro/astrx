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

if (!defined('INDEX_DIR'))         { define('INDEX_DIR', $root . DIRECTORY_SEPARATOR); }
if (!defined('RESOURCES_DIR'))     { define('RESOURCES_DIR', INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR); }
if (!defined('LANG_DIR'))          { define('LANG_DIR', RESOURCES_DIR . 'lang' . DIRECTORY_SEPARATOR); }
if (!defined('CONFIG_DIR'))        { define('CONFIG_DIR', RESOURCES_DIR . 'config' . DIRECTORY_SEPARATOR); }
if (!defined('TEMPLATE_DIR'))      { define('TEMPLATE_DIR', RESOURCES_DIR . 'template' . DIRECTORY_SEPARATOR); }
if (!defined('TEMPLATE_CACHE_DIR')){ define('TEMPLATE_CACHE_DIR', TEMPLATE_DIR . 'cache' . DIRECTORY_SEPARATOR); }
if (!defined('SRC_DIR'))           { define('SRC_DIR', INDEX_DIR . 'src' . DIRECTORY_SEPARATOR); }
if (!defined('CLASS_DIR'))         { define('CLASS_DIR', SRC_DIR . 'AstrX' . DIRECTORY_SEPARATOR); }

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'AstrX\\', 6) !== 0) {
        return;
    }
    $file = CLASS_DIR . str_replace('\\', '/', substr($class, 6)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// Plain functions are not autoloadable and the config files call configDir().
require_once CLASS_DIR . 'Support' . DIRECTORY_SEPARATOR . 'constants.php';

/**
 * Violations this check knows about and deliberately does not fail on, because
 * fixing each one means editing a file outside this change's blast radius.
 * Every entry is a live TODO, not an exemption: if a key here stops reproducing
 * the check FAILS on the stale entry, so the list cannot quietly rot.
 *
 * Key = the stable identifier the check builds for that violation.
 */
const CFG_KNOWN_GAPS = [
    // AstrX\Csrf\CsrfHandler declares both setters but no file declares a
    // 'CsrfHandler' or 'Csrf' section, so both silently never fire and the
    // hardcoded TTL / token size are the only values the app can ever have.
    // Fix: add a Csrf.config.php (or move the keys into an existing section).
    'SETTER:AstrX\\Csrf\\CsrfHandler::setTtl'
        => 'no CsrfHandler/Csrf section exists — needs a Csrf config section',
    'SETTER:AstrX\\Csrf\\CsrfHandler::setTokenBytes'
        => 'no CsrfHandler/Csrf section exists — needs a Csrf config section',
    // The key exists, but under 'WebmailService', which RegisterController does
    // not resolve to. Fix: #[ConfigDomain('WebmailService', file: 'Mail')] on
    // the controller, or read it through getConfigBool('WebmailService', …).
    'SETTER:AstrX\\Controller\\RegisterController::setMailboxIsUsername'
        => "key lives in 'WebmailService'; the controller resolves to RegisterController|Controller",
    // ErrorHandler has no setter for either key and reads neither via
    // getConfig(); both are inert. Fix: give ErrorHandler #[InjectConfig]
    // setters, or delete the keys from resources/config/config.php.
    'CONSUMED:config/ErrorHandler/failsafe_template'
        => 'ErrorHandler hardcodes its failsafe template path',
    'CONSUMED:config/ErrorHandler/production_mask'
        => 'ErrorHandler hardcodes its production error mask',
    // Translator declares neither property. The admin System page validates
    // both, writes both, and reads both back to re-render its own form; nothing
    // else touches them. Fix: give Translator the setters, or drop the fields
    // from the editor and the keys from Translator.config.php.
    'CONSUMED:Translator/Translator/lang_dir'
        => 'Translator has no lang_dir property; only the editor that writes it reads it',
    'CONSUMED:Translator/Translator/fallback_to_key'
        => 'Translator has no fallback_to_key property; only the editor that writes it reads it',
    // Same shape, other owners.
    'CONSUMED:Routing/Routing/default_keys'
        => 'no routing code reads default_keys',
    'CONSUMED:ContentManager/ContentManager/main_page_id'
        => 'ContentManager never reads main_page_id',
    'CONSUMED:ContentManager/ContentManager/extra_lang_domains'
        => 'superseded by per-page lang domains — see the note at ContentManager.php:590',
];

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

foreach (ConfigDomainResolver::configFiles(CONFIG_DIR) as $base => $path) {
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
        if (isset($sections[$section])) {
            $errors["LAYOUT:dup:{$section}"] = "LAYOUT: section '{$section}' is declared twice — in "
                . $sections[$section]['file'] . " and in {$base}";
            continue;
        }
        $keyNames = [];
        if (is_array($keys)) {
            foreach (array_keys($keys) as $k) {
                $keyNames[] = (string) $k;
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
 * Every AstrX class file, mapped to its FQCN.
 *
 * @return list<string>
 */
function cfg_class_names(string $classDir): array
{
    $out = [];
    $it  = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($classDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) { continue; }
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php')) { continue; }
        $rel = substr($path, strlen($classDir));
        $rel = str_replace('\\', '/', $rel);
        // module.php manifests and the constants.php function file hold no class.
        $base = basename($rel);
        if ($base === 'module.php' || $base === 'constants.php') { continue; }
        $out[] = 'AstrX\\' . str_replace('/', '\\', substr($rel, 0, -4));
    }
    sort($out);
    return $out;
}

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

$resolver = new ConfigDomainResolver(CONFIG_DIR);

/** @var array<string,true> $reached sections some class resolves to */
$reached = [];
/** @var array<string,array<string,true>> $setterKeys section => key => true */
$setterKeys = [];

foreach (cfg_class_names(CLASS_DIR) as $fqcn) {
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

$scanRoots = [SRC_DIR, INDEX_DIR . 'public', INDEX_DIR . 'tools'];
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
            . "'{$site['target']}.config.php', which does not exist in resources/config/ — "
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
// 4. Split off the known gaps, and fail on any that no longer reproduce
// ─────────────────────────────────────────────────────────────────────────────

/** @var array<string,string> $known violations accepted for now */
$known = [];
foreach (CFG_KNOWN_GAPS as $key => $why) {
    if (isset($errors[$key])) {
        $known[$key] = $errors[$key] . "\n      (known gap: {$why})";
        unset($errors[$key]);
        continue;
    }
    // The gap was fixed but its entry stayed. Fail: a permanent exemption is
    // how a checker stops catching the thing it was written for.
    $errors["STALE:{$key}"] = "STALE KNOWN GAP: '{$key}' no longer reproduces — "
        . 'delete it from CFG_KNOWN_GAPS in tools/check_config.php';
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. Report
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

foreach ($known as $k) {
    fwrite(STDOUT, "  TODO: {$k}\n");
}
if ($known !== []) { fwrite(STDOUT, "\n"); }

foreach ($errors as $e) {
    fwrite(STDERR, "  ERROR: {$e}\n");
}

if ($errors !== []) {
    fwrite(STDERR, "\n" . count($errors) . " error(s) — config integrity check FAILED.\n");
    exit(1);
}

fwrite(STDOUT, 'Config integrity OK'
    . ($known !== [] ? ' (' . count($known) . " known gap(s) still open)" : '') . ".\n");
exit(0);
