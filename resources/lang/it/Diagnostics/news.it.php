<?php

declare(strict_types = 1);

use AstrX\I18n\Translator;
use AstrX\News\Diagnostic\NewsDbDiagnostic;
use AstrX\Result\DiagnosticInterface;

return [
    'astrx.news/db_error' => function (DiagnosticInterface $d, Translator $t)
    : string {
        assert($d instanceof NewsDbDiagnostic);

        return "Errore database nelle notizie: {$d->message()}.";
    },
];