<?php
declare(strict_types=1);

return [
    'News' => [
        'per_page'    => 20,
        'descending'  => true,

        // Query parameter key names
        'pn_key'      => 'pn',
        'show_key'    => 'show',
        'order_key'   => 'order',

        // Number of page links shown either side of the current page.
        // e.g. window=3, page=5, total=10 → << < 2 3 4 [5] 6 7 8 > >>
        'page_window' => 3,
    ],
];