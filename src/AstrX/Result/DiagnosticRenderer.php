<?php
declare(strict_types=1);

namespace AstrX\Result;

use AstrX\Auth\DiagnosticVisibilityChecker;
use AstrX\I18n\Translator;

/**
 * Renders a DiagnosticInterface into a human-readable, locale-aware string.
 *
 * DiagnosticRenderer maintains its OWN catalog, completely separate from the
 * main Translator catalog. This prevents the recursion where rendering a
 * MissingTranslationDiagnostic would emit another MissingTranslationDiagnostic.
 *
 * Catalog entries are ALWAYS callables:
 *   callable(DiagnosticInterface $d, Translator $t): string
 *
 * The callable receives the fully typed diagnostic and the main Translator
 * (for sub-translations, pluralization, etc.).
 *
 * Fallback (no catalog entry for a given ID):
 *   A [FALLBACK:LEVEL] stamp shows which callable to add to the lang file:
 *     [FALLBACK:NOTICE] astrx.i18n/missing_translation
 *
 * Lang file convention:
 *   Single file:   resources/lang/{locale}/Diagnostics.php
 *   Directory:     resources/lang/{locale}/Diagnostics/
 *                    Core.php, Csrf.php, News.php, …
 *
 * When loadDomain() is called with a name that resolves to a directory, every
 * .php file inside it is loaded in alphabetical order. This allows splitting the
 * catalog by module without changing call sites in ContentManager.
 *
 * Level labels:
 *   The special key 'level_labels' in any loaded file must be array<string,string>
 *   and is stored separately from the callable catalog.
 *   Example: 'level_labels' => ['NOTICE' => 'Avviso', 'ERROR' => 'Errore', ...]
 */
final class DiagnosticRenderer
{
    /**
     * @var array<string, callable(DiagnosticInterface, Translator): string>
     */
    private array $catalog = [];

    /**
     * Translated level labels, keyed by DiagnosticLevel::name.
     *
     * @var array<string, string>
     */
    private array $levelLabels = [];

    public function __construct(private readonly Translator $translator) {}

    // -------------------------------------------------------------------------
    // Domain loading
    // -------------------------------------------------------------------------

    /**
     * Load diagnostic callables from a lang file, directory, or split files.
     *
     * Resolution order (all matching sources are loaded, in order):
     *
     *   1. {langDir}/{locale}/{domain}.{locale}.php   — preferred single file
     *      (falls back to {domain}.php if the suffixed form is absent)
     *
     *   2. {langDir}/{locale}/{domain}/               — subdirectory: every
     *      *.php file inside is loaded alphabetically.
     *
     *   3. {langDir}/{locale}/{domain}.*.{locale}.php — split flat files,
     *      e.g. Diagnostics.core.en.php, Diagnostics.csrf.en.php.
     *      Loaded alphabetically after the main file.
     *      (falls back to {domain}.*.php if no suffixed variants exist)
     *
     * Sources 1/2 and 3 are independent: if both a main file and split files
     * exist, all are loaded. Split files can add or override entries.
     * Later calls for the same key always overwrite earlier ones.
     */
    public function loadDomain(string $langDir, string $domain): void
    {
        $locale = $this->translator->getLocale();
        $dir    = rtrim($langDir, '/\\') . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR;
        $base   = $dir . $domain;

        // --- 1. Main file (locale-suffixed preferred) -------------------------
        $mainSuffixed = $base . '.' . $locale . '.php';
        $mainPlain    = $base . '.php';

        if (is_file($mainSuffixed)) {
            $this->loadFile($mainSuffixed);
        } elseif (is_file($mainPlain)) {
            $this->loadFile($mainPlain);
        }

        // --- 2. Subdirectory --------------------------------------------------
        if (is_dir($base)) {
            $files = glob($base . DIRECTORY_SEPARATOR . '*.php') ?: [];
            sort($files);
            foreach ($files as $file) {
                $this->loadFile($file);
            }
        }

        // --- 3. Split flat files (locale-suffixed preferred) ------------------
        $splitSuffixed = glob($base . '.*.' . $locale . '.php') ?: [];
        $splitPlain    = glob($base . '.*.php') ?: [];

        // Use suffixed variants when any exist; otherwise fall back to plain.
        // Filter out the main file itself to avoid loading it twice.
        $splits = $splitSuffixed !== []
            ? $splitSuffixed
            : array_filter($splitPlain, static fn(string $f): bool =>
                $f !== $mainPlain && $f !== $mainSuffixed
            );

        sort($splits);
        foreach ($splits as $file) {
            $this->loadFile($file);
        }
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render a single diagnostic.
     *
     * Primary:  catalog callable for this diagnostic's ID.
     * Fallback: stamped as [FALLBACK:LEVEL] code.
     */
    public function render(DiagnosticInterface $diagnostic): string
    {
        $id = $diagnostic->id();

        if (array_key_exists($id, $this->catalog)) {
            return ($this->catalog[$id])($diagnostic, $this->translator);
        }

        return $this->fallback($diagnostic);
    }

    /**
     * Render all diagnostics as plain strings.
     *
     * @return list<string>
     */
    public function renderAll(Diagnostics $diagnostics): array
    {
        $out = [];
        foreach ($diagnostics as $d) {
            $out[] = $this->render($d);
        }
        return $out;
    }

    /**
     * Render all diagnostics at or above $minLevel, paired with their level.
     *
     * When $checker is provided:
     *   - The effective level (possibly overridden in DB) is used for the
     *     minLevel comparison instead of the class-declared level.
     *   - Diagnostics the current user may not see are silently skipped.
     *
     * @return list<array{message:string, level:DiagnosticLevel, level_label:string}>
     */
    public function renderFiltered(
        Diagnostics                   $diagnostics,
        DiagnosticLevel               $minLevel,
        ?DiagnosticVisibilityChecker  $checker = null,
    ): array {
        $out = [];
        foreach ($diagnostics as $d) {
            // Apply level override (DB) if checker is available.
            $level = $checker !== null
                ? $checker->effectiveLevel($d)
                : $d->level();

            if ($level->value < $minLevel->value) {
                continue;
            }

            // Visibility check: skip diagnostics the current user may not see.
            if ($checker !== null && !$checker->canSee($d)) {
                continue;
            }

            $out[] = [
                'message'     => $this->render($d),
                'level'       => $level,
                'level_label' => $this->renderLevelLabel($level),
            ];
        }
        return $out;
    }

    /**
     * Return all known diagnostic codes currently registered in the catalog.
     * Used by the admin UI to enumerate available codes for visibility config.
     *
     * @return list<string>
     */
    public function knownCodes(): array
    {
        return array_keys($this->catalog);
    }

    // -------------------------------------------------------------------------
    // Level label
    // -------------------------------------------------------------------------

    /**
     * Resolve the translated label for a DiagnosticLevel.
     * Falls back to the raw enum name if no entry is registered.
     */
    public function renderLevelLabel(DiagnosticLevel $level): string
    {
        return $this->levelLabels[$level->name] ?? $level->name;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function loadFile(string $file): void
    {
        $data = require $file;

        if (!is_array($data)) {
            return;
        }

        // Extract level_labels map — stored separately, not in the callable catalog
        if (isset($data['level_labels']) && is_array($data['level_labels'])) {
            foreach ($data['level_labels'] as $name => $label) {
                if (is_string($name) && is_string($label)) {
                    $this->levelLabels[$name] = $label;
                }
            }
            unset($data['level_labels']);
        }

        foreach ($data as $id => $entry) {
            if (!is_string($id) || !is_callable($entry)) {
                continue;
            }
            $this->catalog[$id] = $entry;
        }
    }

    /**
     * Produce a clearly marked fallback string when no catalog entry exists.
     *
     * Format:  [FALLBACK:LEVEL] id {key=value, key=value}
     * Example: [FALLBACK:NOTICE] astrx.i18n/missing_translation {locale=en, key=foo}
     */
    private function fallback(DiagnosticInterface $diagnostic): string
    {
        // No catalog entry for this code. Show a clearly-stamped stub
        // so the developer knows which callable to add to the lang file.
        return '[FALLBACK:' . $diagnostic->level()->name . '] ' . $diagnostic->id();
    }
}