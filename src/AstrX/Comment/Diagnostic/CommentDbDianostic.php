<?php
declare(strict_types=1);

namespace AstrX\Comment\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class CommentDbDiagnostic extends AbstractDiagnostic
{
    public const string ID    = 'astrx.comment/db_error';
    public const DiagnosticLevel LEVEL = DiagnosticLevel::ERROR;

    public function __construct(string $id, DiagnosticLevel $level,
                                private readonly string $message)
    { parent::__construct($id, $level); }

    public function message(): string { return $this->message; }
    public function vars(): array     { return ['message' => $this->message]; }
}
