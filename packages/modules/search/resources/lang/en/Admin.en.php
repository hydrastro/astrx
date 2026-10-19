<?php
declare(strict_types=1);

/**
 * Admin — en: the Search module's keys (merged into the site's Admin catalog).
 */
return [
    // ── Search index control panel ──────────────────────────────────────────
    'admin.search.heading'         => 'Search index',
    'admin.search.intro'           => 'Rebuild the full-text search index that powers site-wide search. Indexing runs on demand only — freshly-created content is still found live until the next crawl.',
    'admin.search.status'          => 'Job status',
    'admin.search.doc_count'       => 'Indexed documents',
    'admin.search.index_time'      => 'Index last updated',
    'admin.search.last_run'        => 'Last completed run',
    'admin.search.last_message'    => 'Last message',
    'admin.search.freshness'       => 'Freshness',
    'admin.search.stale'           => 'Stale — content newer than the last crawl exists.',
    'admin.search.fresh'           => 'Up to date.',
    'admin.search.never'           => 'Never',
    'admin.search.state_idle'      => 'Idle',
    'admin.search.state_requested' => 'Requested',
    'admin.search.state_running'   => 'Running',
    'admin.search.flash.started'   => 'Search index rebuild started in the background.',
    'admin.search.flash.queued'    => 'Background execution is unavailable — the rebuild was queued for cron/CLI instead.',
    'admin.search.flash.requested' => 'Rebuild queued; it will run on the next cron tick or manual CLI run.',
    'admin.search.flash.reset'     => 'The search index job was reset to idle. You can start a new rebuild now.',
    'admin.search.btn_rebuild'     => 'Rebuild now',
    'admin.search.btn_request'     => 'Queue rebuild for cron',
    'admin.search.btn_reset'       => 'Reset stuck job',
    'admin.search.stale_running'   => 'This job has been marked running far longer than a rebuild should take and is probably stuck — reset it if no crawl is actually in progress.',
    'admin.search.hint_rebuild'    => 'Starts a rebuild immediately in a background process.',
    'admin.search.hint_request'    => 'Flags a rebuild to run on the next scheduled cron tick.',
    'admin.search.hint_exec'       => 'Background execution is unavailable on this host; this button will queue the job for cron/CLI instead.',
    'admin.search.cli_heading'     => 'Manual (command line)',
    'admin.search.cli_intro'       => 'Rebuild the index from a shell:',
    'admin.search.cli_note'        => 'Run this with the php command-line binary (CLI), not php-fpm — the FPM daemon cannot run a CLI script.',
    'admin.search.cron_heading'    => 'Scheduled (cron)',
    'admin.search.cron_intro'      => 'Rebuild every 15 minutes, but only when a rebuild has been requested:',
];
