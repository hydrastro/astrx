<?php
declare(strict_types=1);

/**
 * Navbar display labels — en locale.
 *
 * Keys use the '.label' suffix so they don't collide with the URL slug keys
 * in pages.en.php (e.g. 'WORDING_USER' => 'user' for slugs vs
 * 'WORDING_USER.label' => 'User' for the visible link text).
 *
 * The name column in navbar_entry must match the prefix part (before '.label').
 */
return [
    'WORDING_HOME.label' => 'Home',
    'WORDING_USER.label' => 'User',
];