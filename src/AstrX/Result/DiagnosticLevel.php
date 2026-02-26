<?php
declare(strict_types=1);

namespace AstrX\Result;

enum DiagnosticLevel: int
{
    case DEBUG = 0;
    case INFO = 1;
    case NOTICE = 2;
    case WARNING = 3;
    case ERROR = 4;
    case CRITICAL = 5;
    case ALERT = 6;
    case EMERGENCY = 7;
}
