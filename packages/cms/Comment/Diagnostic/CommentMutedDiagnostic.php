<?php

declare(strict_types = 1);

namespace AstrX\Comment\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** Poster is currently muted on this page. */
final class CommentMutedDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }

}