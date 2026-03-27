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
 * Diagnostic policy (ID + level) is owned by the emitter (Translator), consistent
 * with every other diagnostic in the framework.
 */
final class InvalidLanguageArrayDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $key,
        private readonly string $file,
    ) {
        parent::__construct($id, $level);
    }

    /** The offending array key (or a description if it was not a string). */
    public function key(): string  { return $this->key; }
    public function file(): string { return $this->file; }
}