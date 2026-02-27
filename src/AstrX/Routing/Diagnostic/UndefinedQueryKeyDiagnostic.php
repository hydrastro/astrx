<?php

declare(strict_types = 1);

namespace AstrX\Routing\Diagnostics;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class UndefinedQueryKeyDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $locale,
        private readonly string $canonicalKey
    ) {
        parent::__construct($id, $level);
    }

    public function locale()
    : string
    {
        return $this->locale;
    }

    public function canonicalKey()
    : string
    {
        return $this->canonicalKey;
    }
}