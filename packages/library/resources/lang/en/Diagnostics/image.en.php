<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.image/too_big' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That image is too large.',

    'astrx.image/bad_type' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That file type is not an allowed image.',

    'astrx.image/undecodable' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'That file could not be read as an image.',

    'astrx.image/encode_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'The image could not be processed. Please try again.',
];
