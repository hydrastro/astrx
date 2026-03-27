<?php
declare(strict_types=1);

namespace AstrX\Captcha\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a captcha token is found but has passed its expiry time.
 * The user took too long to submit the form.
 */
final class CaptchaExpiredDiagnostic extends AbstractDiagnostic
{

    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $captchaId,
        private readonly int    $expiredAt,
    ) {
        parent::__construct($id, $level);
    }

    public function captchaId(): string { return $this->captchaId; }
    public function expiredAt(): int    { return $this->expiredAt; }

}