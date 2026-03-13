<?php
declare(strict_types = 1);

/**
 * Translations for the error page — en locale.
 * Covers everything needed by this page and its controller (ErrorController).
 * Loaded by ContentManager immediately after the page is resolved.
 * Note: ErrorController overrides title and description at runtime using the
 * HTTP status code, so the values below are only shown if the controller is
 * somehow absent.
 */
return [
    // ---- meta (fallback only — ErrorController sets these at runtime) -------
    'WORDING_ERROR.title' => 'Page not found',
    'WORDING_ERROR.description' => 'The page you requested could not be found.',

    // ---- keywords -----------------------------------------------------------
    'WORDING_ERROR' => 'error',

    // ---- controller strings -------------------------------------------------
    'error' => 'error',

    'http.status.400' => 'Bad request.',
    'http.status.401' => 'Unauthorised.',
    'http.status.403' => 'Forbidden.',
    'http.status.404' => 'The page you requested could not be found.',
    'http.status.405' => 'Method not allowed.',
    'http.status.500' => 'An internal server error occurred.',
    'http.status.503' => 'Service temporarily unavailable.',
];