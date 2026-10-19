<?php
declare(strict_types=1);

namespace AstrX\Image;

/**
 * Why a sanitize() call failed. Carried as the Result error value so a caller
 * can branch on the reason without parsing a message; the matching user-facing
 * text lives under the `astrx.image/*` diagnostic keys.
 */
enum ImageSanitizeError
{
    case TOO_BIG;
    case BAD_TYPE;
    case UNDECODABLE;
    case ENCODE_FAILED;
}
