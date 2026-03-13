<?php
declare(strict_types=1);

namespace AstrX\Result;

use AstrX\I18n\Translator;

/**
 * Renders a DiagnosticInterface into a human-readable, locale-aware string.
 *
 * DiagnosticRenderer maintains its OWN catalog, completely separate from the
 * main Translator catalog. This prevents the recursion where rendering a
 * MissingTranslationDiagnostic would emit another MissingTranslationDiagnostic.
 *
 * Catalog entries are ALWAYS callables:
 *
 *   callable(DiagnosticInterface $d, Translator $t): string
 *
 * The callable receives the fully typed diagnostic and the main Translator
 * (for sub-translations, pluralization, etc.). It is responsible for casting
 * $d to the concrete class and accessing its typed getters.
 *
 * Example:
 *   'astrx.i18n/missing_translation' =>
 *       function (DiagnosticInterface $d, Translator $t): string {
 *           assert($d instanceof MissingTranslationDiagnostic);
 *           return "Missing key \"{$d->key()}\" for locale \"{$d->locale()}\".";
 *       },
 *
 * Fallback behaviour (when no catalog entry exists for a diagnostic ID):
 *   AbstractDiagnostic::vars() is used to dump all available context, and the
 *   result is visually stamped as a fallback so you immediately know:
 *     a) a callable needs to be written for this ID, and
 *     b) exactly which vars are available to write it with.
 *
 *   Format: [FALLBACK:LEVEL] id {key=value, key=value}
 *
 * Lang file convention:
 *   resources/lang/{locale}/Diagnostics.php  → returns array<string, callable>
 */
final class DiagnosticRenderer
{
    /**
     * @var array<string, callable(DiagnosticInterface, Translator): string>
     */
    private array $catalog = [];

    public function __construct(private readonly Translator $translator) {}

    // -------------------------------------------------------------------------
    // Domain loading
    // -------------------------------------------------------------------------

    /**
     * Load a Diagnostics lang file into the catalog.
     * The file must return array<string, callable>.
     * Non-callable entries are silently skipped.
     * Later calls for the same key overwrite earlier ones (locale layering).
     */
    public function loadDomain(string $langDir, string $domain): void
    {
        $file = rtrim($langDir, '/\\') . DIRECTORY_SEPARATOR
                . $this->translator->getLocale() . DIRECTORY_SEPARATOR
                . $domain . '.php';

        if (!is_file($file)) {
            return;
        }

        $data = require $file;

        if (!is_array($data)) {
            return;
        }

        foreach ($data as $id => $entry) {
            if (!is_string($id) || !is_callable($entry)) {
                continue;
            }
            $this->catalog[$id] = $entry;
        }
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render a single diagnostic.
     *
     * Primary:  catalog callable for this diagnostic's ID.
     * Fallback: vars() dump, visually stamped as [FALLBACK:LEVEL].
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
     * Render all diagnostics at or above $minLevel, returning each entry
     * paired with its DiagnosticLevel for use by the template layer.
     *
     * @return list<array{message: string, level: DiagnosticLevel}>
     */
    public function renderFiltered(
        Diagnostics    $diagnostics,
        DiagnosticLevel $minLevel,
    ): array {
        $out = [];
        foreach ($diagnostics as $d) {
            if ($d->level()->value < $minLevel->value) {
                continue;
            }
            $out[] = [
                'message' => $this->render($d),
                'level'   => $d->level(),
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Fallback
    // -------------------------------------------------------------------------

    /**
     * Produce a clearly marked fallback string when no catalog entry exists.
     *
     * Format:  [FALLBACK:LEVEL] id {key=value, key=value}
     *
     * This tells you at a glance:
     *   - which diagnostic ID needs a callable in the Diagnostics lang file
     *   - which vars() keys are available to use inside that callable
     *
     * If vars() is empty the vars block is omitted:
     *   [FALLBACK:ERROR] astrx.injector/class_not_found
     */
    private function fallback(DiagnosticInterface $diagnostic): string
    {
        $prefix = '[FALLBACK:' . $diagnostic->level()->name . '] ' . $diagnostic->id();

        $vars = $diagnostic->vars();
        if ($vars === []) {
            return $prefix;
        }

        $pairs = [];
        foreach ($vars as $k => $v) {
            $pairs[] = $k . '=' . (string) $v;
        }

        return $prefix . ' {' . implode(', ', $pairs) . '}';
    }
}