<?php
declare(strict_types=1);

namespace AstrX\User\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/** PDO exception originating from a user-table operation. */
final class UserDbDiagnostic extends AbstractDiagnostic
{
    public const string ID    = 'astrx.user/db_error';
    public const DiagnosticLevel LEVEL = DiagnosticLevel::ERROR;

    public function __construct(
        string $id,
        DiagnosticLevel $level,
        private readonly string $message,
    ) {
        parent::__construct($id, $level);
    }

    public function message(): string { return $this->message; }

    public function vars(): array { return ['message' => $this->message]; }
}