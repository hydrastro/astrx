<?php
declare(strict_types=1);

use AstrX\Captcha\CaptchaType;

return [
    'CaptchaService' => [
        // How long a generated captcha token is valid, in seconds.
        'captcha_expiration' => 600, // 10 minutes
    ],
    'CaptchaRenderer' => [
        // Canvas size — 1 = auto-size to fit the text.
        'image_width'  => 1,
        'image_height' => 1,

        // Colors as 3- or 6-char hex strings (without #).
        'background_color' => '000000',
        'text_color'       => 'ffffff',
        'lines_color'      => 'ffffff',
        'dots_color'       => 'ffffff',

        // Set to true to give each element a random color.
        'text_color_random'  => false,
        'lines_color_random' => false,
        'dots_color_random'  => false,

        // Noise overlay
        'lines_start_from_border' => true,
        'lines_number'            => 10,
        'dots_number'             => 100,

        // Character set — ambiguous chars (0/O, 1/l/I) are excluded by default.
        'char_list'      => '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ',
        'captcha_length' => 5,

        // Difficulty: 0=EASY, 1=MEDIUM, 2=HARD
        'captcha_type' => CaptchaType::MEDIUM->value,

        // Font
        'font_size' => 20,
        'font_file' => defined('RESOURCES_DIR') ? RESOURCES_DIR . '/fonts/FSEX300.ttf' : 'fonts/FSEX300.ttf',

        // MEDIUM: random spacing between characters (pixels)
        'font_min_distance' => 0,
        'font_max_distance' => 10,

        // Rotation angle range (degrees)
        'font_min_angle' => -45,
        'font_max_angle' => 45,

        // Padding around text in the image
        'font_x_border' => 5,
        'font_y_border' => 5,

        // HARD: color of the trace line connecting real chars
        'trace_line_color' => 'ff0000',

        // HARD: number of decoy characters drawn without a trace
        'non_captcha_char_number' => 5,

        // Border randomness algorithm for line endpoints
        'use_border_linear_randomness' => true,

        // HARD: max placement attempts before falling back to MEDIUM
        'max_rounds_number' => 5000,
    ],
];
