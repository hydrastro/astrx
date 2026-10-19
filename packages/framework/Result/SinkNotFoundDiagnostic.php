<?php

declare(strict_types = 1);

namespace AstrX\Result;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;
use LogicException;

final class SinkNotFoundDiagnostic extends LogicException
{

}