<?php

declare(strict_types = 1);

namespace AstrX\Comment\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** The reply target belongs to a different page. */
final class CommentReplyWrongPageDiagnostic extends AbstractDiagnostic
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