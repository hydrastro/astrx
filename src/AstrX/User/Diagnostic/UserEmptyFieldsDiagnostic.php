<?php

declare(strict_types = 1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** One or more required fields were not supplied. */
final class UserEmptyFieldsDiagnostic extends AbstractDiagnostic
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