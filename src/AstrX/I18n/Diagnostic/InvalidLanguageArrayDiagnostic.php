<?php
declare(strict_types=1);

namespace AstrX\I18n\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

final class InvalidLanguageArrayDiagnostic extends AbstractDiagnostic
{
    public const ID  = 'astrx.i18n/invalid_language_array';
    public const LVL = DiagnosticLevel::ERROR;

    public function __construct()
    {
        parent::__construct(self::ID, self::LVL);
    }
}