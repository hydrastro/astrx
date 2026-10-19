<?php
declare(strict_types=1);

namespace AstrX\Image;

/**
 * The product of sanitizing one untrusted image: re-encoded (metadata-stripped)
 * full-image bytes plus any derived data requested. Storage and naming are the
 * caller's responsibility — this object never touches the filesystem.
 */
final class SanitizedImage
{
    public function __construct(
        public readonly string  $fullBytes,
        public readonly string  $mime,          // image/jpeg | image/png
        public readonly string  $ext,           // jpg | png
        public readonly int     $width,
        public readonly int     $height,
        public readonly ?string $thumbBytes  = null,
        public readonly ?int    $thumbWidth  = null,
        public readonly ?int    $thumbHeight = null,
        public readonly ?int    $averageHash = null,   // 64-bit aHash (signed PHP int) for near-dup detection
        public readonly ?string $sha256      = null,   // hex digest of the sanitized full bytes
    ) {}
}
