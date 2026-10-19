<?php
declare(strict_types=1);

/**
 * Admin — it: the Search module's keys (merged into the site's Admin catalog).
 */
return [
    // ── Pannello di controllo dell'indice di ricerca ────────────────────────
    'admin.search.heading'         => 'Indice di ricerca',
    'admin.search.intro'           => 'Ricostruisci l\'indice full-text che alimenta la ricerca del sito. L\'indicizzazione è solo su richiesta — i contenuti appena creati vengono comunque trovati in tempo reale fino alla prossima scansione.',
    'admin.search.status'          => 'Stato del processo',
    'admin.search.doc_count'       => 'Documenti indicizzati',
    'admin.search.index_time'      => 'Ultimo aggiornamento dell\'indice',
    'admin.search.last_run'        => 'Ultima esecuzione completata',
    'admin.search.last_message'    => 'Ultimo messaggio',
    'admin.search.freshness'       => 'Aggiornamento',
    'admin.search.stale'           => 'Obsoleto — esistono contenuti più recenti dell\'ultima scansione.',
    'admin.search.fresh'           => 'Aggiornato.',
    'admin.search.never'           => 'Mai',
    'admin.search.state_idle'      => 'Inattivo',
    'admin.search.state_requested' => 'Richiesto',
    'admin.search.state_running'   => 'In esecuzione',
    'admin.search.flash.started'   => 'Ricostruzione dell\'indice avviata in background.',
    'admin.search.flash.queued'    => 'Esecuzione in background non disponibile — ricostruzione accodata per cron/CLI.',
    'admin.search.flash.requested' => 'Ricostruzione accodata; verrà eseguita al prossimo cron o avvio manuale da CLI.',
    'admin.search.flash.reset'     => 'Il processo dell\'indice di ricerca è stato reimpostato su inattivo. Ora puoi avviare una nuova ricostruzione.',
    'admin.search.btn_rebuild'     => 'Ricostruisci ora',
    'admin.search.btn_request'     => 'Accoda ricostruzione per cron',
    'admin.search.btn_reset'       => 'Reimposta processo bloccato',
    'admin.search.stale_running'   => 'Questo processo risulta in esecuzione da molto più tempo di quanto dovrebbe richiedere una ricostruzione ed è probabilmente bloccato — reimpostalo se nessuna scansione è effettivamente in corso.',
    'admin.search.hint_rebuild'    => 'Avvia subito una ricostruzione in un processo in background.',
    'admin.search.hint_request'    => 'Segnala una ricostruzione da eseguire alla prossima esecuzione pianificata di cron.',
    'admin.search.hint_exec'       => 'L\'esecuzione in background non è disponibile su questo host; questo pulsante accoderà invece il processo per cron/CLI.',
    'admin.search.cli_heading'     => 'Manuale (riga di comando)',
    'admin.search.cli_intro'       => 'Ricostruisci l\'indice da una shell:',
    'admin.search.cli_note'        => 'Esegui questo comando con il binario php da riga di comando (CLI), non con php-fpm — il demone FPM non può eseguire uno script CLI.',
    'admin.search.cron_heading'    => 'Pianificato (cron)',
    'admin.search.cron_intro'      => 'Ricostruisci ogni 15 minuti, ma solo quando è stata richiesta una ricostruzione:',
];
