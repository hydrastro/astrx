<?php
declare(strict_types=1);

return [
    'CaptchaService' => [
        'captcha_expiration' => 600,
    ],
    'CaptchaRenderer' => [
        'image_width' => 1,
        'image_height' => 1,
        'background_color' => '000000',
        'text_color' => 'ffffff',
        'lines_color' => 'ffffff',
        'dots_color' => 'ffffff',
        'text_color_random' => false,
        'lines_color_random' => false,
        'dots_color_random' => false,
        'lines_start_from_border' => true,
        'lines_number' => 7,
        'dots_number' => 1000,
        'char_list' => '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ',
        'captcha_length' => 5,
        'captcha_type' => 1,
        'font_size' => 20,
        'font_file' => '/app/resources//fonts/FSEX300.ttf',
        'font_min_distance' => 0,
        'font_max_distance' => 10,
        'font_min_angle' => -45,
        'font_max_angle' => 45,
        'font_x_border' => 5,
        'font_y_border' => 5,
        'trace_line_color' => 'ff0000',
        'non_captcha_char_number' => 5,
        'use_border_linear_randomness' => true,
        'max_rounds_number' => 5000,
    ],
];
