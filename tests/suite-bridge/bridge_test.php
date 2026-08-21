<?php
declare(strict_types=1);

/**
 * Standalone bridge test — NO AstrX bootstrap.
 *
 * Boots tests/mock_search_server.php on the PHP built-in server, then drives the
 * real WebSearchClient / OnionSearchClient fetch+parse+sanitise path against it
 * and asserts:
 *   1. untrusted result fields come back tag-free (no <script>, no <mark>);
 *   2. entity-encoded text is decoded to clean plain text;
 *   3. a javascript: result URL is reduced to a safe href '#';
 *   4. paging metadata (total/page/page_size) is read per engine spelling;
 *   5. an unreachable backend returns ok=false (no crash / no warning-to-500);
 *   6. a non-JSON body returns ok=false.
 *
 * Run:  php tests/bridge_test.php
 */

// --- minimal stub so the holders' #[InjectConfig] attribute always resolves ---
namespace AstrX\Config {
    if (!\class_exists(InjectConfig::class)) {
        #[\Attribute(\Attribute::TARGET_METHOD)]
        final class InjectConfig
        {
            public function __construct(public string $key) {}
        }
    }
}

namespace {

    use AstrX\WebSearch\WebSearchConfig;
    use AstrX\WebSearch\WebSearchClient;
    use AstrX\OnionSearch\OnionSearchConfig;
    use AstrX\OnionSearch\OnionSearchClient;

    $BASE = dirname(__DIR__, 2) . '/src/AstrX';
    require $BASE . '/WebSearch/WebSearchConfig.php';
    require $BASE . '/WebSearch/WebSearchClient.php';
    require $BASE . '/OnionSearch/OnionSearchConfig.php';
    require $BASE . '/OnionSearch/OnionSearchClient.php';

    $PASS = 0;
    $FAIL = 0;
    function check(string $label, bool $cond): void
    {
        global $PASS, $FAIL;
        if ($cond) { $PASS++; echo "  ok   - $label\n"; }
        else       { $FAIL++; echo "  FAIL - $label\n"; }
    }
    function eq(string $label, mixed $got, mixed $want): void
    {
        check($label . " (got: " . var_export($got, true) . ")", $got === $want);
    }
    function noMarkup(string $label, string $s): void
    {
        check(
            $label . " tag-free (value: " . var_export($s, true) . ")",
            !str_contains($s, '<') && !str_contains($s, '>')
        );
    }

    /**
     * A localhost port nothing is listening on, probed upward from $from.
     *
     * The port used to be the constant 8811, which made this test fail as "mock
     * server never became ready" for reasons that have nothing to do with the
     * code under test: a CI runner with its own service there, a leftover server
     * from an interrupted run, a second copy of the suite in parallel.
     */
    function freePort(int $from): int
    {
        for ($port = $from; $port < $from + 200; $port++) {
            $probe = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
            if ($probe !== false) {
                fclose($probe);          // released; the child binds it a moment later
                return $port;
            }
        }
        fwrite(STDERR, "no free port in [{$from}, " . ($from + 200) . ")\n");
        exit(2);
    }

    // ---- boot the mock server -------------------------------------------------
    // escapeshellarg(PHP_BINARY), not a bare `php`: $PATH can resolve to a
    // different interpreter than the one running this test (a system 8.1 next to
    // the 8.4 under test), and the mock is written against this one.
    $mock  = __DIR__ . '/mock_search_server.php';
    $proc  = null;
    $pipes = [];
    $port  = 0;
    $ready = false;

    for ($attempt = 0; $attempt < 5 && !$ready; $attempt++) {
        $port = freePort(8811);
        $cmd  = sprintf('exec %s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg($mock));
        $proc = proc_open($cmd, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($proc)) {
            fwrite(STDERR, "could not start mock server\n");
            exit(2);
        }
        // wait for readiness
        for ($i = 0; $i < 50; $i++) {
            $h = @file_get_contents("http://127.0.0.1:$port/healthz");
            if ($h === 'ok') { $ready = true; break; }
            // Child gone = it lost the race for the port between our probe
            // closing and its own bind(). Take another port instead of calling
            // that a mock-server failure.
            if (proc_get_status($proc)['running'] === false) { break; }
            usleep(100_000);
        }
        if (!$ready) {
            proc_terminate($proc);
            foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
            proc_close($proc);
            $proc = null;
        }
    }
    if (!$ready || !is_resource($proc)) {
        fwrite(STDERR, "mock server never became ready\n");
        exit(2);
    }

    try {
        // ---- 1. clear-web client against the live mock -----------------------
        echo "[websearch] live backend\n";
        $wc = new WebSearchConfig();
        $wc->setBaseUrl("http://127.0.0.1:$port");
        $web = new WebSearchClient($wc);
        $r = $web->search('hello', 2);

        eq('ok=true',            $r['ok'],        true);
        eq('total=35',           $r['total'],     35);
        eq('page=2',             $r['page'],      2);
        eq('page_size=10',       $r['page_size'], 10);
        eq('2 results',          count($r['results']), 2);

        $r0 = $r['results'][0];
        eq('title decoded+stripped', $r0['title'], 'Hello World & friends');
        eq('href kept (http)',       $r0['href'],  'https://example.com/a');
        eq('snippet cleaned',        $r0['snippet'], 'an intro hello and alert(1) tail');
        noMarkup('snippet', $r0['snippet']);
        check('snippet has no <mark>',  !str_contains($r0['snippet'], 'mark'));
        check('snippet has no <script>',!str_contains($r0['snippet'], 'script'));

        $r1 = $r['results'][1];
        eq('javascript: href neutralised', $r1['href'], '#');
        eq('malicious snippet cleaned',    $r1['snippet'], 'steal()plain');
        noMarkup('malicious snippet', $r1['snippet']);

        // ---- 2. onion client (reads snippet + per_page) ----------------------
        echo "[onionsearch] live backend\n";
        $oc = new OnionSearchConfig();
        $oc->setBaseUrl("http://127.0.0.1:$port");
        $onion = new OnionSearchClient($oc);
        $o = $onion->search('hello', 1);
        eq('ok=true',           $o['ok'],        true);
        eq('page_size=10 (per_page)', $o['page_size'], 10);
        eq('title cleaned',     $o['results'][0]['title'], 'Hello World & friends');
        eq('snippet cleaned',   $o['results'][0]['snippet'], 'an intro hello and alert(1) tail');
        noMarkup('onion snippet', $o['results'][0]['snippet']);
        eq('js href neutralised', $o['results'][1]['href'], '#');

        // ---- 3. non-JSON body degrades gracefully ----------------------------
        echo "[websearch] non-JSON body\n";
        $bad = $web->search('html', 1);
        eq('ok=false on non-JSON', $bad['ok'], false);
        eq('no results on non-JSON', count($bad['results']), 0);

        // ---- 4. unreachable backend degrades gracefully ----------------------
        echo "[websearch] unreachable backend (no warning-to-500)\n";
        $dc = new WebSearchConfig();
        $dc->setBaseUrl('http://127.0.0.1:1');   // connection refused
        $dead = (new WebSearchClient($dc))->search('hello', 1);
        eq('ok=false when down', $dead['ok'], false);
        eq('no results when down', count($dead['results']), 0);

        // ---- 5. config hardening: non-http scheme rejected to default --------
        echo "[config] scheme hardening\n";
        $ev = new WebSearchConfig();
        $ev->setBaseUrl('file:///etc/passwd');
        eq('file:// rejected to default', $ev->baseUrl(), WebSearchConfig::DEFAULT_BASE_URL);
        $ev->setBaseUrl('http://127.0.0.1:8803/');
        eq('trailing slash trimmed', $ev->baseUrl(), 'http://127.0.0.1:8803');
        $ev->setTimeoutSeconds(999);
        eq('timeout clamped', $ev->timeoutSeconds(), WebSearchConfig::MAX_TIMEOUT);

    } finally {
        proc_terminate($proc);
        foreach ($pipes as $p) { if (is_resource($p)) { fclose($p); } }
        proc_close($proc);
    }

    echo "\n==== $PASS passed, $FAIL failed ====\n";
    exit($FAIL === 0 ? 0 : 1);
}
