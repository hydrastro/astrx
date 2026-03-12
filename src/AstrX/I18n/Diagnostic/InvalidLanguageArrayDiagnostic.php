<?php
declare(strict_types=1);

namespace AstrX\I18n\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a language file is valid PHP and returns an array, but one or
 * more entries in that array have a non-string key or an unsupported value type.
 *
 * Distinct from InvalidLanguageFileDiagnostic (which covers missing files and
 * files that do not return an array at all).
 *
 * Emitted by Translator::loadFile() when it encounters a bad entry.
 */
final class InvalidLanguageArrayDiagnostic extends AbstractDiagnostic
{
    // TODO: set id and lvl OUTSIDE this class! set the in the translator, where you create this
    public const string ID  = 'astrx.i18n/invalid_language_array';
    public const DiagnosticLevel LVL = DiagnosticLevel::WARNING;

    public function __construct(
        private readonly string $key,
        private readonly string $file,
    ) {
        parent::__construct(self::ID, self::LVL);
    }

    /** The offending array key (or a string representation if it was not a string). */
    public function key(): string  { return $this->key; }
    public function file(): string { return $this->file; }
}
