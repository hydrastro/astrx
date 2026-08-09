<?php
declare(strict_types=1);

/**
 * Tiny mock of the two Python search engines' JSON API, used only by
 * bridge_test.php. Run via:  php -S 127.0.0.1:<port> mock_search_server.php
 *
 * It returns ONE payload that carries BOTH engines' field spellings
 * (snippet_html + snippet, page_size + per_page) so the same mock exercises the
 * clear-web AND the onion client. The malicious fields (a <script>/<mark>
 * snippet and a javascript: URL) prove the sanitiser.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/healthz') {
    header('Content-Type: text/plain');
    echo 'ok';
    return true;
}

if ($path !== '/api/search') {
    http_response_code(404);
    echo 'not found';
    return true;
}

$q = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '';

// Special case: prove the "non-JSON body" branch degrades gracefully.
if ($q === 'html') {
    header('Content-Type: text/html');
    echo '<html><body>totally not json</body></html>';
    return true;
}

$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;

$payload = [
    'query'     => $q,
    'page'      => $page,
    'page_size' => 10,   // clear-web spelling
    'per_page'  => 10,   // onion spelling
    'total'     => 35,
    'results'   => [
        [
            'url'          => 'https://example.com/a',
            'title'        => 'Hello <b>World</b> &amp; friends',
            'host'         => 'example.com',
            'snippet_html' => 'an intro <mark>hello</mark> and &lt;script&gt;alert(1)&lt;/script&gt; tail',
            'snippet'      => 'an intro <mark>hello</mark> and &lt;script&gt;alert(1)&lt;/script&gt; tail',
        ],
        [
            'url'          => 'javascript:alert(document.cookie)',
            'title'        => 'evil result',
            'host'         => 'evil',
            'snippet_html' => '<script>steal()</script>plain',
            'snippet'      => '<script>steal()</script>plain',
        ],
    ],
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_UNESCAPED_SLASHES);
return true;
