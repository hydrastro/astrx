<?php
declare(strict_types=1);

namespace AstrX\I18n\Diagnostics;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class MissingTranslationDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $locale,
        private readonly string $key
    ) {
        parent::__construct($id, $level);
    }

    public function locale(): string { return $this->locale; }
    public function key(): string { return $this->key; }
}