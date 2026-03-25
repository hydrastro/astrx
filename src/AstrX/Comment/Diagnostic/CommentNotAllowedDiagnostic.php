<?php

declare(strict_types = 1);

namespace AstrX\Comment\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** User group is not permitted to post comments. */
final class CommentNotAllowedDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }

    public function vars()
    : array
    {
        return [];
    }
}