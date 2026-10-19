<?php

declare(strict_types = 1);

namespace AstrX\News\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a PDO operation on the news table throws an exception.
 */
final class NewsDbDiagnostic extends AbstractDiagnostic
{

    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $message,
    ) {
        parent::__construct($id, $level);
    }

    public function message()
    : string
    {
        return $this->message;
    }

}