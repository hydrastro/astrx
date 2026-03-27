<?php

declare(strict_types = 1);

return [
    'IdenticonRenderer' => [
        // Output size in pixels (square).
        'size' => 256,

        // Grid complexity — number of tiles along each axis.
        // More tiles = more intricate pattern. Must be >= 2.
        'tiles' => 6,

        // Number of distinct colors derived from the input hash.
        // 1 = monochrome per identicon, 2 = two-tone, etc.
        'colors' => 1,

        // High-quality mode: renders at a larger internal resolution and
        // downsamples. Smoother edges, slightly higher memory and CPU cost.
        'high_quality' => true,
    ],
];