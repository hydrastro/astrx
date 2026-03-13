<?php

declare(strict_types = 1);

/**
 * Translations for the main page — en locale.
 * Covers everything needed by this page and its controller (MainController).
 * Loaded by ContentManager immediately after the page is resolved.
 * Key convention:
 *   WORDING_MAIN.title       — <title> tag
 *   WORDING_MAIN.description — <meta name="description">
 *   WORDING_MAIN_PAGE        — keyword (keyword.i18n = 1 in DB)
 *   main_page                — string used by MainController
 */
return [
    // ---- meta ---------------------------------------------------------------
    'WORDING_MAIN.title' => 'Home',
    'WORDING_MAIN.description' => 'Welcome to the website.',

    // ---- keywords -----------------------------------------------------------
    'WORDING_MAIN_PAGE' => 'home',

    // ---- controller strings -------------------------------------------------
    'main_page' => 'main page',
];