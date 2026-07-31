<?php
declare(strict_types=1);

/**
 * Media library module — upload constraints for the general-purpose uploaded-media
 * manager (list / upload / rename / delete, re-usable across content pages).
 *
 * Consumed by MediaConfig via #[InjectConfig] (section key = class short name,
 * exactly like Imageboard.config.php → ImageboardConfig). Every uploaded image is
 * validated + re-encoded through the SAME shared ImageSanitizer the imageboard
 * uses, so these knobs map 1:1 onto ImageSanitizeOptions: EXIF/metadata is
 * stripped by the re-encode, the pixel budget rejects decompression bombs before
 * decode, and the byte cap bounds the work.
 */
return [
    'MediaConfig' => [
        // Where re-encoded media is written. A dedicated sub-directory under the
        // resources uploads area, kept separate from board_uploads so the two
        // file controllers never share a namespace.
        'upload_dir'         => getenv('MEDIA_UPLOAD_DIR') ?: '/app/resources/media_uploads',
        'upload_max_kb'      => (int) (getenv('MEDIA_UPLOAD_MAX_KB') ?: 4096),
        'upload_max_pixels'  => (int) (getenv('MEDIA_UPLOAD_MAX_PIXELS') ?: 16000000), // header pixel-budget: reject decompression bombs pre-decode
        'full_max_dimension' => (int) (getenv('MEDIA_FULL_MAX_DIMENSION') ?: 2048),     // full image downscaled to fit this box on re-encode
        // Accepted upload extensions. Constrained to the servable set
        // (jpg,jpeg,png,gif,webp); gif/webp are accepted as input and re-encoded
        // to png (metadata stripped, animation dropped).
        'upload_types'       => getenv('MEDIA_UPLOAD_TYPES') ?: 'jpg,jpeg,png,gif,webp',
    ],
];
