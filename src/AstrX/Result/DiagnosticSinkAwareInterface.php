<?php
declare(strict_types=1);

namespace AstrX\Result;

interface DiagnosticSinkAwareInterface
{
    public function setDiagnosticSink(DiagnosticSinkInterface $sink): void;
}
