<?php
declare(strict_types=1);

namespace AstrX\Captcha\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a captcha ID is submitted but not found in the database.
 * Typically means the captcha has already been used, never existed, or
 * the session was cleared.
 */
final class CaptchaNotFoundDiagnostic extends AbstractDiagnostic
{

    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $captchaId,
    ) {
        parent::__construct($id, $level);
    }

    public function captchaId(): string { return $this->captchaId; }

}