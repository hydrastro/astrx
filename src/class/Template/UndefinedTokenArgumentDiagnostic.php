<?php
declare(strict_types=1);

namespace AstrX\Template;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class UndefinedTokenArgumentDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $token,
    ) {
        parent::__construct($id, $level);
    }

    public function token(): string { return $this->token; }
}