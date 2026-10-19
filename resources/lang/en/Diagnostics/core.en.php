<?php

declare(strict_types = 1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;
use AstrX\Session\Diagnostic\InvalidPrgIdDiagnostic;

/**
 * Diagnostics — en: the site's request pipeline (a page hidden from the
 * visitor, a stale POST/redirect/GET token).
 */
return [

    // Session
    'astrx.session/invalid_prg_id' => function (
        DiagnosticInterface $d,
        Translator $t
    )
    : string {
        assert($d instanceof InvalidPrgIdDiagnostic);

        return "Invalid or unknown PRG token \"" .
               ($d->prgId()??'(null)') .
               "\" in POST body.";
    },

    // Content — page hidden notice (admin viewing a hidden page)
    'astrx.content/page_hidden' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        '⚠ Admin view: this page is hidden from public visitors.',
];
