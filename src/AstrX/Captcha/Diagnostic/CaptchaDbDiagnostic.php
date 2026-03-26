<?php
declare(strict_types=1);

namespace AstrX\Captcha\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a PDO operation on the captcha table throws an exception.
 */
final class CaptchaDbDiagnostic extends AbstractDiagnostic
{

    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $message,
    ) {
        parent::__construct($id, $level);
    }

    public function message(): string { return $this->message; }

}