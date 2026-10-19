<?php
declare(strict_types=1);

namespace AstrX\Media\Diagnostic;

use AstrX\Result\AbstractDiagnostic;
use AstrX\Result\DiagnosticLevel;

/**
 * A user-facing media-upload outcome (transport error, unwritable upload dir,
 * failed disk write, name collision). The id maps to an `astrx.media/*`
 * translation key. Validation/decode failures are emitted separately by the
 * shared ImageSanitizer (astrx.image/*) and surface through the same Result.
 */
final class MediaUploadDiagnostic extends AbstractDiagnostic
{
    public function __construct(string $id, DiagnosticLevel $level)
    {
        parent::__construct($id, $level);
    }
}
