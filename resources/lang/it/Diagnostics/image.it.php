<?php
declare(strict_types=1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.image/too_big' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Immagine troppo grande.',

    'astrx.image/bad_type' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Questo tipo di file non è un\'immagine consentita.',

    'astrx.image/undecodable' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Impossibile leggere il file come immagine.',

    'astrx.image/encode_failed' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        'Impossibile elaborare l\'immagine. Riprova.',
];
