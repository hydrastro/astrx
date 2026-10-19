<?php
declare(strict_types=1);

/**
 * Site-wide search — en locale.
 *
 * Loaded by SiteSearchController via loadDomain(langDir(), 'Search').
 * Keys mirror the it counterpart 1:1 (check_lang_parity.php).
 */
return [
    'search.heading'             => 'Search',
    'search.query_label'         => 'Search terms',
    'search.submit'              => 'Search',
    'search.type_label'          => 'Search in',
    'search.no_results'          => 'No results found.',
    'search.result_count'        => '{count} result(s)',

    // Type-filter dropdown options.
    'search.type_option.all'      => 'Everything',
    'search.type_option.news'     => 'News',
    'search.type_option.pages'    => 'Pages',
    'search.type_option.comments' => 'Comments',
    'search.type_option.board'    => 'Board posts',

    // Per-result type labels (the pill shown next to each hit).
    'search.type.news'     => 'News',
    'search.type.pages'    => 'Page',
    'search.type.comments' => 'Comment',
    'search.type.board'    => 'Board post',
];
