<?php
declare(strict_types=1);

namespace AstrX\Image\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * A user-facing image-sanitization failure (too big / wrong type / undecodable
 * / encode failed). The id maps to an `astrx.image/*` translation key.
 */
final class ImageSanitizeDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
