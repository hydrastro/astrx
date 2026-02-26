<?php

declare(strict_types = 1);

namespace AstrX\Template;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class TemplateFileReadFailedDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $file,
    ) {
        parent::__construct($id, $level);
    }

    public function file()
    : string
    {
        return $this->file;
    }
}