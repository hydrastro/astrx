<?php
declare(strict_types=1);

namespace AstrX\Result;

abstract class AbstractDiagnostic implements DiagnosticInterface, \Stringable
{
    public function __construct(
        private readonly string $id,
        private readonly DiagnosticLevel $level,
    ) {}

    final public function id(): string
    {
        return $this->id;
    }

    final public function level(): DiagnosticLevel
    {
        return $this->level;
    }

    public function __toString(): string
    {
        return $this->id;
    }
}
