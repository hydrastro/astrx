<?php
declare(strict_types=1);

namespace AstrX\Captcha\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a captcha token is found and valid but the submitted text
 * does not match the stored text.
 */
final class CaptchaWrongDiagnostic extends AbstractDiagnostic
{
    public const string ID    = 'astrx.captcha/wrong_text';
    public const DiagnosticLevel LEVEL = DiagnosticLevel::NOTICE;

    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
