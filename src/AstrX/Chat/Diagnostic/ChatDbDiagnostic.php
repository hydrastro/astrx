<?php
declare(strict_types=1);

namespace AstrX\Chat\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class ChatDbDiagnostic extends AbstractDiagnostic
{

    public function __construct(string $id, DiagnosticLevel $level,
        private readonly string $message)
    { parent::__construct($id, $level); }

    public function message(): string { return $this->message; }
}
