<?php
declare(strict_types=1);

namespace AstrX\Comment\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * Business-logic failure from the comment subsystem.
 *
 * Known operations:
 *   not_allowed        — user group not permitted to post
 *   flood              — posting too fast
 *   antispam           — content failed antispam regex
 *   empty_content      — blank comment submitted
 *   reply_not_found    — reply_to id doesn't exist
 *   reply_wrong_page   — reply target is on a different page
 *   invalid_email      — anonymous commenter email invalid
 *   comment_not_found  — action on a non-existent comment
 *   gate_denied        — permission check failed
 */
final class CommentDiagnostic extends AbstractDiagnostic
{
    public const string ID    = 'astrx.comment/operation';
    public const DiagnosticLevel LEVEL = DiagnosticLevel::NOTICE;

    public function __construct(string $id, DiagnosticLevel $level,
                                private readonly string $operation,
                                private readonly string $detail = '')
    { parent::__construct($id, $level); }

    public function operation(): string { return $this->operation; }
    public function detail(): string    { return $this->detail; }
    public function vars(): array       { return ['operation' => $this->operation]; }
}
