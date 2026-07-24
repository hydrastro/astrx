<?php
declare(strict_types=1);

namespace AstrX\Imageboard\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * A user-facing imageboard post/validation outcome (empty, too long, locked,
 * no such board/thread, image failed, disabled). The id maps to an
 * `astrx.imageboard/*` translation key.
 */
final class ImageboardPostDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
