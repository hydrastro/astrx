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
    public const string ID    = 'astrx.captcha/db_error';
    public const DiagnosticLevel LEVEL = DiagnosticLevel::ERROR;

    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $message,
    ) {
        parent::__construct($id, $level);
    }

    public function message(): string { return $this->message; }

    public function vars(): array
    {
        return ['message' => $this->message];
    }
}
