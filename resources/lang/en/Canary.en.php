<?php
declare(strict_types=1);

/**
 * Warrant canary — public page — en locale. Keys mirror the it counterpart 1:1
 * (check_lang_parity.php). Loaded automatically for the 'canary' page.
 */
return [
    'canary.heading'       => 'Warrant canary',
    'canary.intro'         => 'The signed statement below is re-attested periodically. Verify its signature against the operator key published elsewhere before relying on it.',
    'canary.last_attested' => 'Last attested',
    'canary.stale_warning' => 'This canary is overdue: it has not been re-attested within the expected interval. Treat its continued validity with suspicion.',
    'canary.not_published' => 'No warrant canary is currently published.',
];
