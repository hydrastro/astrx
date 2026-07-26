<?php
declare(strict_types=1);

namespace AstrX\I18n;

use AstrX\I18n\Diagnostic\LangWriteDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Result;

use function AstrX\Support\langDir;

/**
 * Reads and writes the flat translation catalogs under resources/lang/<locale>/.
 *
 * Backs the admin Language editor: browse a domain's keys, edit every locale's
 * string side by side, save; and add a whole new language (a new locale) by
 * cloning an existing one. Also supplies the full key list to the comments
 * antispam message picker.
 *
 * Only the TOP-LEVEL domains (Admin, Comment, Content, …) are exposed for
 * editing — the nested Diagnostics/ catalogs are developer-facing and stay out
 * of the editor, but they ARE cloned when a language is added so the new locale
 * is complete from day one.
 *
 * Locales are plain strings validated against available_languages in the app
 * config (ContentManager resolves the request locale that way — no fixed enum),
 * so a language added here works on the next request once the admin page also
 * registers it in the config.
 *
 * Write strategy mirrors ConfigWriter: serialise to a sibling .tmp file,
 * rename() into place (atomic on POSIX), opcache_invalidate(). Values are
 * single-quoted PHP strings with addcslashes() escaping.
 *
 * SAFETY: a domain whose catalog holds a non-string value (a callable entry,
 * which the Translator also accepts) is reported non-editable — a closure
 * cannot be re-serialised to source, so saving would silently drop it. Such a
 * domain is shown read-only rather than corrupted. No top-level catalog ships
 * closures today; this guards a future one.
 */
final class LangCatalog
{
    private const string PRIMARY = 'en';

    /** Domain basenames: letters, digits, underscore; letter-initial. */
    private const string DOMAIN_RE = '/^[A-Za-z][A-Za-z0-9_]*$/';

    /** Locale codes: en, it, fr, pt_BR, zh_Hant — letters + optional _region. */
    private const string LOCALE_RE = '/^[a-z]{2,3}(_[A-Za-z0-9]{2,8})?$/';

    /**
     * Installed locales — the immediate sub-directories of resources/lang/ that
     * look like locale codes. The primary locale sorts first (it is the
     * translation reference); the rest follow alphabetically.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        $root = rtrim(langDir(), '/\\') . DIRECTORY_SEPARATOR;
        $out  = [];
        foreach (glob($root . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (preg_match(self::LOCALE_RE, $name) === 1) {
                $out[] = $name;
            }
        }
        sort($out);
        // Primary first, if present.
        if (in_array(self::PRIMARY, $out, true)) {
            $out = array_values(array_filter($out, static fn(string $l): bool => $l !== self::PRIMARY));
            array_unshift($out, self::PRIMARY);
        }
        return $out;
    }

    public function primary(): string
    {
        return self::PRIMARY;
    }

    public function localeExists(string $code): bool
    {
        return in_array($code, $this->locales(), true);
    }

    /**
     * Every editable top-level domain, sorted. Discovered from the primary
     * locale directory.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        $dir = $this->localeDir(self::PRIMARY);
        $out = [];
        foreach (glob($dir . '*.' . self::PRIMARY . '.php') ?: [] as $file) {
            $base = basename($file, '.' . self::PRIMARY . '.php');
            if ($base !== '' && preg_match(self::DOMAIN_RE, $base) === 1) {
                $out[] = $base;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Load one domain's strings across every installed locale for editing.
     *
     * @return array{locales: list<string>, values: array<string, array<string,string>>, editable: bool}
     */
    public function load(string $domain): array
    {
        if (!$this->safeDomain($domain)) {
            return ['locales' => [], 'values' => [], 'editable' => false];
        }

        $locales  = $this->locales();
        $values   = [];
        $editable = true;
        foreach ($locales as $locale) {
            $raw              = $this->readRaw($this->file($domain, $locale));
            $values[$locale]  = $this->stringsOnly($raw);
            $editable         = $editable && $this->allStrings($raw);
        }

        return ['locales' => $locales, 'values' => $values, 'editable' => $editable];
    }

    /**
     * Every string key of every editable domain, from the primary locale,
     * sorted. Feeds the antispam message picker (a datalist of keys).
     *
     * @return array<string, list<string>>
     */
    public function allKeys(): array
    {
        $out = [];
        foreach ($this->domains() as $domain) {
            $keys = array_keys($this->stringsOnly($this->readRaw($this->file($domain, self::PRIMARY))));
            sort($keys);
            $out[$domain] = $keys;
        }
        return $out;
    }

    /**
     * Overwrite a domain's catalogs with the edited values, one file per
     * installed locale. Edit-only: the written key set is the union of the
     * on-disk keys across all locales (primary order first, then any extras),
     * so every locale file ends up with the identical key set — the editor
     * doubles as a parity-filler. Posted values that map to a known key are
     * applied; unknown posted keys are ignored.
     *
     * @param array<string, array<string,string>> $byLocale locale => (key => value)
     * @return Result<bool>
     */
    public function save(string $domain, array $byLocale): Result
    {
        if (!$this->safeDomain($domain)) {
            return $this->fail('astrx.i18n/lang_domain_invalid', "Invalid language domain '{$domain}'.");
        }

        $locales = $this->locales();

        // Read current catalogs + build the union key order (primary first).
        $current = [];
        $order   = [];
        $seen    = [];
        foreach ($locales as $locale) {
            $raw = $this->readRaw($this->file($domain, $locale));
            if (!$this->allStrings($raw)) {
                return $this->fail(
                    'astrx.i18n/lang_not_editable',
                    "Domain '{$domain}' contains dynamic (callable) entries and cannot be edited here."
                );
            }
            $current[$locale] = $this->stringsOnly($raw);
            foreach (array_keys($current[$locale]) as $key) {
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $order[]    = $key;
                }
            }
        }

        foreach ($locales as $locale) {
            $posted = $byLocale[$locale] ?? [];
            $cur    = $current[$locale];
            $out    = [];
            foreach ($order as $key) {
                $out[$key] = $posted[$key] ?? $cur[$key] ?? '';
            }
            $w = $this->writeFile($domain, $locale, $out);
            if (!$w->isOk()) {
                return $w;
            }
        }

        return Result::ok(true);
    }

    /**
     * Add a new language by cloning an existing locale's entire catalog tree
     * (top-level domains + nested Diagnostics/) into resources/lang/<code>/.
     * The clone is seeded with the source strings so the site is fully rendered
     * immediately; the admin then translates the top-level domains in the
     * editor. The caller registers <code> in available_languages afterwards.
     *
     * @return Result<bool>
     */
    public function addLanguage(string $code, string $source): Result
    {
        if (preg_match(self::LOCALE_RE, $code) !== 1) {
            return $this->fail('astrx.i18n/lang_code_invalid', "Invalid language code '{$code}'.");
        }
        if ($this->localeExists($code)) {
            return $this->fail('astrx.i18n/lang_exists', "Language '{$code}' already exists.");
        }
        if (!$this->localeExists($source)) {
            return $this->fail('astrx.i18n/lang_source_missing', "Source language '{$source}' does not exist.");
        }

        return $this->cloneTree($this->localeDir($source), $this->localeDir($code), $source, $code);
    }

    /**
     * Remove an installed language: delete its whole resources/lang/<code>/ tree.
     * The primary locale can never be deleted (it is the translation reference
     * and the ultimate fallback). The caller unregisters <code> from
     * available_languages afterwards.
     *
     * @return Result<bool>
     */
    public function deleteLanguage(string $code): Result
    {
        if (preg_match(self::LOCALE_RE, $code) !== 1) {
            return $this->fail('astrx.i18n/lang_code_invalid', "Invalid language code '{$code}'.");
        }
        if ($code === self::PRIMARY) {
            return $this->fail('astrx.i18n/lang_primary_protected', "The primary language '{$code}' cannot be deleted.");
        }
        if (!$this->localeExists($code)) {
            return $this->fail('astrx.i18n/lang_source_missing', "Language '{$code}' does not exist.");
        }

        return $this->rmTree($this->localeDir($code));
    }

    // -------------------------------------------------------------------------

    /**
     * Recursively delete a directory tree.
     *
     * @return Result<bool>
     */
    private function rmTree(string $dir): Result
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . $entry;
            if (is_dir($path)) {
                $r = $this->rmTree($path . DIRECTORY_SEPARATOR);
                if (!$r->isOk()) {
                    return $r;
                }
            } elseif (!unlink($path)) {
                return $this->fail('astrx.i18n/lang_delete_failed', "Could not delete {$path}.");
            }
        }
        if (!rmdir(rtrim($dir, '/\\'))) {
            return $this->fail('astrx.i18n/lang_delete_failed', "Could not remove {$dir}.");
        }
        return Result::ok(true);
    }

    /** @return Result<bool> */
    private function cloneTree(string $srcDir, string $dstDir, string $source, string $code): Result
    {
        if (!is_dir($dstDir) && !mkdir($dstDir, 0o775, true) && !is_dir($dstDir)) {
            return $this->fail('astrx.i18n/lang_mkdir_failed', "Could not create {$dstDir}.");
        }

        $suffix = '.' . $source . '.php';
        foreach (scandir($srcDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $srcPath = $srcDir . $entry;
            if (is_dir($srcPath)) {
                $r = $this->cloneTree($srcPath . DIRECTORY_SEPARATOR, $dstDir . $entry . DIRECTORY_SEPARATOR, $source, $code);
                if (!$r->isOk()) {
                    return $r;
                }
                continue;
            }
            if (is_file($srcPath) && str_ends_with($entry, $suffix)) {
                $base    = substr($entry, 0, -strlen($suffix));
                $dstPath = $dstDir . $base . '.' . $code . '.php';
                if (!copy($srcPath, $dstPath)) {
                    return $this->fail('astrx.i18n/lang_copy_failed', "Could not copy {$srcPath}.");
                }
            }
        }
        return Result::ok(true);
    }

    /**
     * @param array<string,string> $values
     * @return Result<bool>
     */
    private function writeFile(string $domain, string $locale, array $values): Result
    {
        $path = $this->file($domain, $locale);
        $php  = $this->render($domain, $locale, $values);
        $tmp  = $path . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            return $this->fail('astrx.i18n/lang_write_failed', "Could not write {$tmp}.");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return $this->fail('astrx.i18n/lang_write_failed', "Could not replace {$path}.");
        }
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }
        return Result::ok(true);
    }

    /** @param array<string,string> $values */
    private function render(string $domain, string $locale, array $values): string
    {
        $out  = "<?php\n";
        $out .= "declare(strict_types=1);\n\n";
        $out .= "/**\n";
        $out .= " * {$domain} — {$locale} locale.\n";
        $out .= " * Managed by the admin Language editor. Keys mirror the other locales 1:1.\n";
        $out .= " */\n";
        $out .= "return [\n";
        foreach ($values as $key => $value) {
            $out .= '    ' . $this->quote($key) . ' => ' . $this->quote($value) . ",\n";
        }
        $out .= "];\n";
        return $out;
    }

    private function quote(string $s): string
    {
        return "'" . addcslashes($s, "'\\") . "'";
    }

    /**
     * require the catalog file and return its raw array (mixed values), or an
     * empty array when absent / not an array.
     *
     * @return array<mixed,mixed>
     */
    private function readRaw(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        /** @var mixed $data */
        $data = require $file;
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<mixed,mixed> $raw
     * @return array<string,string>
     */
    private function stringsOnly(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @param array<mixed,mixed> $raw */
    private function allStrings(array $raw): bool
    {
        foreach ($raw as $k => $v) {
            if (!is_string($k) || !is_string($v)) {
                return false;
            }
        }
        return true;
    }

    private function safeDomain(string $domain): bool
    {
        return preg_match(self::DOMAIN_RE, $domain) === 1;
    }

    private function localeDir(string $locale): string
    {
        return rtrim(langDir(), '/\\') . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR;
    }

    private function file(string $domain, string $locale): string
    {
        return $this->localeDir($locale) . $domain . '.' . $locale . '.php';
    }

    /** @return Result<bool> */
    private function fail(string $id, string $message): Result
    {
        return Result::err(false, Diagnostics::of(
            new LangWriteDiagnostic($id, DiagnosticLevel::ERROR, $message)
        ));
    }
}
