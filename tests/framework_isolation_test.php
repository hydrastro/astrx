<?php
declare(strict_types=1);

/**
 * The framework layers run on their own — at runtime, not just in the import
 * graph.
 *
 * tools/check_architecture.php proves no framework namespace IMPORTS a higher
 * layer. That cannot see string class names, global constants, file paths
 * assumed to exist, or the autoloader quietly finding application code. This
 * test closes that gap:
 *
 *   1. copy ONLY the foundation + component namespaces (as listed by
 *      `check_architecture.php --list`) and the class loader into an empty
 *      directory, next to a copy of examples/minimal;
 *   2. confirm that tree cannot load a feature or application class;
 *   3. serve the example from it with PHP's built-in server and exercise every
 *      route: HTML page (escaped output, security headers), POST/redirect/GET,
 *      JSON, 404.
 *
 * Standalone: no bootstrap, no database. Needs proc_open and a free TCP port.
 */

$root = dirname(__DIR__);
$PASS = 0;
$FAIL = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  ok   - {$label}\n";
    } else {
        $FAIL++;
        echo "  FAIL - {$label}" . ($detail !== '' ? "\n         {$detail}" : '') . "\n";
    }
}

function copyTree(string $from, string $to): void
{
    if (!is_dir($to) && !mkdir($to, 0o700, true)) {
        throw new RuntimeException("cannot create {$to}");
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        $dest = $to . substr($item->getPathname(), strlen($from));
        if ($item->isDir()) {
            if (!is_dir($dest)) { mkdir($dest, 0o700, true); }
        } elseif (!copy($item->getPathname(), $dest)) {
            throw new RuntimeException("cannot copy {$item->getPathname()}");
        }
    }
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/** @return array{status:int, headers:array<string,string>, body:string} */
function http(string $method, string $url, string $body = ''): array
{
    $ctx = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => $body !== '' ? "Content-Type: application/x-www-form-urlencoded\r\n" : '',
        'content'         => $body,
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 10,
    ]]);
    $out     = @file_get_contents($url, false, $ctx);
    $status  = 0;
    $headers = [];
    /** @var list<string> $http_response_header */
    foreach ($http_response_header ?? [] as $i => $line) {
        if ($i === 0 && preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
            $status = (int) $m[1];
        } elseif (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $out === false ? '' : $out];
}

// ── 1. Build the isolated tree ──────────────────────────────────────────────
$framework = array_values(array_filter(array_map(
    'trim',
    explode("\n", (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/check_architecture.php') . ' --list=foundation,component')),
)));
$tmp = rtrim(sys_get_temp_dir(), '/') . '/astrx-isolation-' . bin2hex(random_bytes(6));
mkdir($tmp . '/src/AstrX', 0o700, true);
foreach (['autoload', 'classloader', 'sources', 'support'] as $loader) {
    copy($root . '/src/' . $loader . '.php', $tmp . '/src/' . $loader . '.php');
}
foreach ($framework as $ns) {
    copyTree($root . '/src/AstrX/' . $ns, $tmp . '/src/AstrX/' . $ns);
}
copyTree($root . '/examples/minimal', $tmp . '/app');

echo "1. Isolated tree\n";
check('framework layers listed by the architecture gate (expected 10+)', count($framework) >= 10, implode(', ', $framework));
$present = array_values(array_diff(scandir($tmp . '/src/AstrX') ?: [], ['.', '..']));
sort($present);
$expected = $framework;
sort($expected);
check('the tree holds exactly the framework namespaces', $present === $expected, implode(', ', $present));

// ── 2. Application code is unreachable from it ──────────────────────────────
echo "\n2. Nothing above the framework is reachable\n";
$probe = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(
    'require ' . var_export($tmp . '/src/autoload.php', true) . ';'
    . 'echo json_encode(['
    . '"framework" => class_exists("AstrX\\\\Template\\\\TemplateEngine"),'
    . '"feature" => class_exists("AstrX\\\\User\\\\UserSession"),'
    . '"library" => class_exists("AstrX\\\\Auth\\\\Gate"),'
    . '"application" => class_exists("AstrX\\\\ContentManager"),'
    . ']);'
);
/** @var array<string,bool> $loaded */
$loaded = json_decode((string) shell_exec($probe), true) ?: [];
check('a framework class loads', ($loaded['framework'] ?? false) === true);
check('a feature class does not (User\\UserSession)', ($loaded['feature'] ?? true) === false);
check('a library class does not (Auth\\Gate)', ($loaded['library'] ?? true) === false);
check('an application class does not (ContentManager)', ($loaded['application'] ?? true) === false);

// ── 3. Serve the example from the isolated tree ─────────────────────────────
echo "\n3. examples/minimal served from the isolated tree\n";
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = $sock !== false ? (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1) : 0;
if ($sock !== false) { fclose($sock); }
$env  = ['ASTRX_AUTOLOAD' => $tmp . '/src/autoload.php', 'PATH' => (string) getenv('PATH')];
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", $tmp . '/app/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']],
    $pipes,
    $tmp . '/app',
    $env,
);
$base = "http://127.0.0.1:{$port}";
for ($i = 0; $i < 50 && http('GET', $base . '/hello.json')['status'] === 0; $i++) {
    usleep(100_000);
}

try {
    $home = http('GET', $base . '/?name=' . rawurlencode('<b>Ada</b>'));
    check('GET / → 200', $home['status'] === 200, (string) $home['status'] . ' ' . substr($home['body'], 0, 300));
    check('it is the rendered template', str_contains($home['body'], '<h1>Hello from the framework layers</h1>'));
    check('the {{#features}} loop rendered its items', substr_count($home['body'], '<li>') === 3);
    check('translation with a placeholder works, and input is escaped',
        str_contains($home['body'], 'Hello, &lt;b&gt;Ada&lt;/b&gt;!'));
    check('HTML responses carry the security headers',
        str_contains($home['headers']['content-security-policy'] ?? '', "default-src 'none'")
        && ($home['headers']['x-frame-options'] ?? '') === 'DENY');
    check('no JavaScript anywhere in the page', !str_contains(strtolower($home['body']), '<script'));

    $post = http('POST', $base . '/', 'name=Grace');
    check('POST / → 303 See Other (POST/redirect/GET)', $post['status'] === 303, (string) $post['status']);
    check('the redirect carries the submitted value', ($post['headers']['location'] ?? '') === '/?name=Grace');

    $json = http('GET', $base . '/hello.json?name=Linus');
    check('GET /hello.json → 200 application/json',
        $json['status'] === 200 && str_starts_with($json['headers']['content-type'] ?? '', 'application/json'));
    check('JSON body', json_decode($json['body'], true) === ['greeting' => 'Hello, Linus!'], $json['body']);

    $missing = http('GET', $base . '/no-such-page');
    check('unknown path → 404 with the not-found template',
        $missing['status'] === 404 && str_contains($missing['body'], 'Page not found'));

    $log = (string) @file_get_contents($tmp . '/server.log');
    check('no PHP errors or warnings while serving', !preg_match('/(Fatal|Warning|Notice|Deprecated)/', $log), $log);
} finally {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    rmTree($tmp);
}

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
