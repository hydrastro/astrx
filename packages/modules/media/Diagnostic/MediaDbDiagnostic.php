<?php
declare(strict_types=1);

namespace AstrX\Media\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a PDO operation performed by the media module throws.
 */
final class MediaDbDiagnostic extends AbstractDiagnostic
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
