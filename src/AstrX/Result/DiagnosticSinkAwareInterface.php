<?php
namespace AstrX\Result;

interface DiagnosticSinkAwareInterface
{
    public function setDiagnosticSink(DiagnosticSinkInterface $sink): void;
}