<?php
declare(strict_types=1);

/**
 * Translations for the main page — en locale.
 * Loaded by ContentManager via ucfirst($page->fileName) = 'Main'.
 *
 * Key convention:
 *   WORDING_MAIN.title       — <title> tag
 *   WORDING_MAIN.description — <meta name="description">
 *   news.*                   — strings used by MainController / main.html
 */
return [
    // Page meta
    'WORDING_MAIN.title'       => 'Home',
    'WORDING_MAIN.description' => 'Welcome to the website.',

    // News listing
    'news.heading'         => 'News',
    'news.date'            => 'Date',
    'news.empty'           => 'There are no news yet.',
    'news.prev'            => '← Previous',
    'news.next'            => 'Next →',
    'news.page'            => 'Page',

    // Filter form
    'news.filter.show'     => 'Items per page',
    'news.filter.order'    => 'Order',
    'news.filter.desc'     => 'Newest first',
    'news.filter.asc'      => 'Oldest first',
    'news.filter.submit'   => 'Apply',
];