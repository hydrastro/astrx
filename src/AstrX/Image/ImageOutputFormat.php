<?php
declare(strict_types=1);

namespace AstrX\Image;

/**
 * Output format for a sanitized image.
 *
 * AUTO keeps a JPEG source as JPEG (photos stay small) and re-encodes
 * everything else as PNG — the historical avatar/chat behaviour.
 */
enum ImageOutputFormat
{
    case AUTO;
    case PNG;
    case JPEG;
}
