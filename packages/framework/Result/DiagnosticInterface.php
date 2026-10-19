<?php
declare(strict_types=1);

namespace AstrX\Result;

interface DiagnosticInterface
{
    public function id(): string;
    public function level(): DiagnosticLevel;
}
