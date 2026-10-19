<?php
declare(strict_types=1);

namespace AstrX\Captcha;

/**
 * Captcha difficulty level.
 *
 *   EASY   — single straight-line render, no rotation.
 *   MEDIUM — chars scattered from both edges inward with random angles.
 *   HARD   — fully random placement, decoy chars, trace line connecting
 *            the real chars in order (the user follows the line).
 */
enum CaptchaType: int
{
    case EASY   = 0;
    case MEDIUM = 1;
    case HARD   = 2;
}
