<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Retention\RetentionSource;
use AstrX\Retention\UploadStore;

/**
 * What the imageboard keeps, for the retention engine: posts are removed by
 * their board's lifecycle, not by age, so there is no target — but each
 * deleted post's images (full size and thumbnail) stay on disk until the
 * orphan reaper sees that no board_image row names them.
 */
final class ImageboardRetention implements RetentionSource
{
    public function __construct(private readonly ImageboardConfig $config) {}

    public function retentionTargets(): array
    {
        return [];
    }

    public function uploadStores(): array
    {
        return [new UploadStore(
            $this->config->uploadDir(),
            ['SELECT `full_name` AS n FROM `board_image` UNION SELECT `thumb_name` FROM `board_image`'],
        )];
    }
}
