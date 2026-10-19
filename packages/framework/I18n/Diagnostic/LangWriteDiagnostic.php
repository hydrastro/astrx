<?php
declare(strict_types=1);

namespace AstrX\I18n\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when the language editor cannot read or write a translation file.
 */
final class LangWriteDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $message,
    ) {
        parent::__construct($id, $level);
    }

    public function message(): string
    {
        return $this->message;
    }
}
