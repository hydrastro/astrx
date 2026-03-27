<?php
declare(strict_types=1);

namespace AstrX\Comment\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class CommentDbDiagnostic extends AbstractDiagnostic
{

    public function __construct(string $id, DiagnosticLevel $level,
        private readonly string $message)
    { parent::__construct($id, $level); }

    public function message(): string { return $this->message; }
}