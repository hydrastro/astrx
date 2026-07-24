<?php
declare(strict_types=1);

namespace AstrX\Image;

/**
 * Immutable options for one ImageSanitizer::sanitize() call. Each consumer
 * constructs one describing its own limits and output needs, so the shared
 * sanitizer stays free of any module-specific configuration.
 */
final class ImageSanitizeOptions
{
    /**
     * @param list<string> $allowedExtensions lower-case (e.g. ['jpg','png']); empty = allow any decodable image
     */
    public function __construct(
        public readonly array             $allowedExtensions  = [],
        public readonly int               $maxBytes           = 0,   // 0 = no limit
        public readonly int               $maxDimension       = 0,   // 0 = no downscale of the full image
        public readonly ImageOutputFormat $outputFormat       = ImageOutputFormat::AUTO,
        public readonly int               $jpegQuality        = 85,
        public readonly bool              $makeThumbnail      = false,
        public readonly int               $thumbMaxDimension  = 250,
        public readonly bool              $computeAverageHash = false,
        public readonly bool              $computeSha256      = false,
    ) {}
}
