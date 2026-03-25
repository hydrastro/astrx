<?php

declare(strict_types = 1);

namespace AstrX\Comment\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** Comment body failed the antispam regex. detail = the pattern that triggered. */
final class CommentAntispamDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        public readonly string $detail = '',
    ) {
        parent::__construct($id, $level);
    }

    public function vars()
    : array
    {
        return ['detail' => $this->detail];
    }
}