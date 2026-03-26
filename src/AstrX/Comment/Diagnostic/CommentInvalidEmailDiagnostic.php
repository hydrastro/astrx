<?php

declare(strict_types = 1);

namespace AstrX\Comment\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** Anonymous commenter supplied an invalid email address. */
final class CommentInvalidEmailDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }

}