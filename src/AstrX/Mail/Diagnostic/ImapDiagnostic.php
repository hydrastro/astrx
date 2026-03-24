<?php

declare(strict_types = 1);

namespace AstrX\Mail\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Emitted when any IMAP operation fails.
 */
final class ImapDiagnostic extends AbstractDiagnostic
{
    public const string ID = 'astrx.mail/imap.error';
    public const DiagnosticLevel LEVEL = DiagnosticLevel::ERROR;

    /**
     * @param string $operation e.g. 'connect', 'login', 'select', 'fetch'
     * @param string $detail    Server error message or exception message
     */
    public function __construct(
        string $id,
        DiagnosticLevel $level,
        public readonly string $operation,
        public readonly string $detail = '',
    ) {
        parent::__construct($id, $level);
    }

    public function translationKey()
    : string
    {
        return 'astrx.mail.imap.' . $this->operation;
    }

    public function context()
    : array
    {
        return ['detail' => $this->detail];
    }
}