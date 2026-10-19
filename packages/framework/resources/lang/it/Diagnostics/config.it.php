<?php

declare(strict_types = 1);

use AstrX\Config\Diagnostic\ConfigFileInvalidDiagnostic;
use AstrX\Config\Diagnostic\ConfigNotABoolDiagnostic;
use AstrX\Config\Diagnostic\ConfigNotFoundDiagnostic;
use AstrX\Config\Diagnostic\ConfigWriteDiagnostic;
use AstrX\I18n\Translator;
use AstrX\Result\DiagnosticInterface;

/**
 * Diagnostiche del livello di configurazione emesse da ConfigWriter e
 * ModuleLoader — locale it. Senza una voce qui DiagnosticRenderer stampa
 * "[FALLBACK:ERROR] astrx.config/write_failed" e DefaultTemplateContext
 * inserisce quella stringa letterale nella pagina di amministrazione.
 */
return [
    'astrx.config/write_failed' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigWriteDiagnostic);
            return "Impossibile salvare il file di configurazione ({$d->reason}): {$d->path}. "
                . 'Nulla è stato modificato — controlla i permessi del file.';
        },

    // Emessa quando una sezione viene scritta in un file diverso da quello che
    // la dichiara; ConfigWriter reindirizza la scrittura e lo segnala.
    'astrx.config/write_retargeted' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigWriteDiagnostic);
            return "Sezione di configurazione salvata nel file che la dichiara ({$d->path}) "
                . "invece che in quello richiesto dall'editor ({$d->reason}).";
        },

    // Raggiungibile solo con config_optional / lang_optional disattivati.
    'astrx.config/resource_missing' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigFileInvalidDiagnostic);
            return "Risorsa attesa non trovata: {$d->getFile()} — il codice che la richiede "
                . 'sta usando i valori predefiniti interni.';
        },

    // Livello DEBUG: una chiave letta ma non dichiarata da nessuna parte, per cui
    // è stato usato il valore predefinito del chiamante.
    'astrx.config/get_config.defaulted' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigNotFoundDiagnostic);
            return "La chiave di configurazione \"{$d->getConfigName()}\" è assente dalla sezione "
                . "\"{$d->getClassShortName()}\" — è stato usato il valore predefinito.";
        },

    'astrx.config/not_a_bool' =>
        function (DiagnosticInterface $d, Translator $t): string {
            assert($d instanceof ConfigNotABoolDiagnostic);
            return "Il flag di configurazione \"{$d->domain()}.{$d->key()}\" contiene un {$d->actual()}, "
                . 'non un booleano. Usa true o false. È stato usato il valore predefinito.';
        },
];
