<?php

declare(strict_types = 1);

use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;
use AstrX\Session\Diagnostic\InvalidPrgIdDiagnostic;

/**
 * Diagnostics — it: the site's request pipeline (a page hidden from the
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

        return "Token PRG \"" .
               ($d->prgId()??'(null)') .
               "\" non valido nel corpo POST.";
    },

    // Content — page hidden notice (admin viewing a hidden page)
    'astrx.content/page_hidden' =>
        fn(DiagnosticInterface $d, Translator $t): string =>
        '⚠ Vista amministratore: questa pagina è nascosta ai visitatori pubblici.',
];
