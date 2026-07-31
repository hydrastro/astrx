<?php
declare(strict_types=1);

namespace AstrX\Invite\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when a PDO operation performed by the invite module throws.
 */
final class InviteDbDiagnostic extends AbstractDiagnostic
{
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $message,
    ) {
        parent::__construct($id, $level);
    }

    public function message(): string
    {
        return $this->message;
    }
}
