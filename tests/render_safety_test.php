<?php
declare(strict_types=1);

/**
 * Standalone render-safety test — NO AstrX bootstrap, no database.
 *
 * Covers three defects in the path that turns a request into bytes:
 *
 *   1. The compiled-template cache was written with file_put_contents() and then
 *      require_once'd. file_put_contents truncates first, so a concurrent
 *      request require'd a half-written PHP class — an uncaught ParseError, i.e.
 *      a 500. Support\atomicWrite() publishes by rename() instead.
 *
 *   2. ErrorHandler recorded every masked PHP error into the one list the
 *      shutdown handler escalated, so ANY E_WARNING appended
 *      "<h1>Internal Server Error</h1>" to an otherwise successful response —
 *      including, in the reviewer's repro, to a user's downloaded mail
 *      attachment. Recording and escalating are now separate.
 *
 *   3. The admin System-config form wrote template_dir / template_cache_dir /
 *      template_extension / parse_mode straight from POST. A blank template_dir
 *      bricks every render including the page that would fix it, and the
 *      PARSE_MODE_PLAIN codegen interpolated template_dir into a double-quoted
 *      PHP literal that is eval'd.
 *
 * Everything is written under a temp directory; the repo tree is never touched.
 *
 * Run:  php tests/render_safety_test.php
 */

namespace {

    use AstrX\Config\ConfigWriter;
    use AstrX\Controller\AdminConfigSystemController;
    use AstrX\Imageboard\BoardView;
    use AstrX\Page\Page;
    use AstrX\Result\DiagnosticsCollector;
    use AstrX\Result\Result;
    use AstrX\Session\FlashBag;
    use AstrX\Template\TemplateEngine;
    use function AstrX\Support\atomicWrite;

    $ROOT      = dirname(__DIR__);
    $CLASS_DIR = $ROOT . '/src/AstrX/';

    $TMP = sys_get_temp_dir() . '/astrx-render-safety-' . bin2hex(random_bytes(4));
    mkdir($TMP, 0755, true);

    // configDir() reads this at call time — point it at the temp dir so the
    // ConfigWriter round trip below cannot touch resources/config. ConfigWriter
    // writes into an existing directory (a real deploy always has one), so
    // create it here rather than having the write fail for the wrong reason.
    define('CONFIG_DIR', $TMP . '/config/');
    mkdir(CONFIG_DIR, 0755, true);

    require_once $CLASS_DIR . 'Support/constants.php';
    spl_autoload_register(static function (string $class) use ($CLASS_DIR): void {
        if (strncmp($class, 'AstrX\\', 6) !== 0) { return; }
        $file = $CLASS_DIR . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) { require_once $file; }
    });

    $PASS = 0;
    $FAIL = 0;

    function check(string $label, bool $cond): void
    {
        global $PASS, $FAIL;
        if ($cond) { $PASS++; echo "  ok   - $label\n"; }
        else       { $FAIL++; echo "  FAIL - $label\n"; }
    }

    function eq(string $label, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;
        check($label . ($ok ? '' : ' (expected ' . var_export($expected, true)
                                 . ', got ' . var_export($actual, true) . ')'), $ok);
    }

    function rmTree(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // =========================================================================
    echo "\n1. Support\\atomicWrite()\n";

    $file = $TMP . '/cache/deep/compiled.php';
    check('creates missing directories', atomicWrite($file, '<?php return 1;'));
    eq('round-trips the exact bytes', '<?php return 1;', (string) file_get_contents($file));

    $before = fileinode($file);
    clearstatcache();
    atomicWrite($file, '<?php return 2;');
    clearstatcache();
    $afterAtomic = fileinode($file);
    // A different inode is the observable signature of tmp+rename: the old file
    // was replaced whole. An in-place write keeps the inode and has a window in
    // which the file is truncated — which is precisely what a concurrent
    // require_once used to land in.
    check('publishes a NEW inode (rename), never truncating the live file', $before !== $afterAtomic);

    $plainTarget = $TMP . '/cache/plain.php';
    file_put_contents($plainTarget, 'a');
    clearstatcache();
    $plainBefore = fileinode($plainTarget);
    file_put_contents($plainTarget, 'bb');
    clearstatcache();
    check(
        'and file_put_contents does NOT — same inode, i.e. truncate in place',
        $plainBefore === fileinode($plainTarget),
    );

    eq('leaves no staging files behind', [], glob($TMP . '/cache/deep/*.tmp.*') ?: []);

    // A short write must not be published: a full disk would otherwise rename a
    // truncated file into place, which is the exact failure being prevented.
    check('refuses to publish into a path that cannot be created', !atomicWrite('/proc/astrx/nope.php', 'x'));

    // =========================================================================
    echo "\n2. TemplateEngine cache + PARSE_MODE_PLAIN\n";

    // A template directory whose name contains the characters that used to be
    // interpolated into an eval'd double-quoted literal.
    $hostileDir = $TMP . '/tpl-$x-"q"/';
    mkdir($hostileDir, 0755, true);
    file_put_contents($hostileDir . 'plain.html', 'PLAIN-BODY-OK');
    file_put_contents($hostileDir . 'greet.html', 'Hello {{name}}!');

    $cacheDir = $TMP . '/tplcache/';

    $engine = new TemplateEngine(new DiagnosticsCollector());
    $engine->setTemplateDir($hostileDir);
    $engine->setTemplateCacheDir($cacheDir);
    $engine->setTemplateExtension('.html');
    $engine->setCacheTemplates(true);

    $rendered = $engine->renderTemplate('greet', ['name' => 'world']);
    eq('template mode renders from a directory containing $ and "', 'Hello world!', $rendered->unwrap());
    check('the compiled class was cached', is_file($cacheDir . 'greet.php'));
    eq('no staging file survives in the cache dir', [], glob($cacheDir . '*.tmp.*') ?: []);

    // PARSE_MODE_PLAIN: used to check <name>.html for existence and then read
    // <name>.php, so every page rendered 200 with an EMPTY body.
    $plain = new TemplateEngine(new DiagnosticsCollector());
    $plain->setTemplateDir($hostileDir);
    $plain->setTemplateCacheDir($TMP . '/tplcache-plain/');
    $plain->setTemplateExtension('.html');
    $plain->setParseMode(TemplateEngine::PARSE_MODE_PLAIN);
    eq(
        'plain mode reads the SAME file it checked, not <name>.php',
        'PLAIN-BODY-OK',
        $plain->renderTemplate('plain')->unwrap(),
    );

    // The injection itself: a directory name that closes the string literal and
    // appends code. If the generated source still interpolated it, the marker
    // file would exist after the render.
    $marker    = $TMP . '/pwned.txt';
    $escapeDir = $TMP . '/esc' . '"; file_put_contents(' . var_export($marker, true) . ", 'x'); \$z=\"" . '/';
    if (@mkdir($escapeDir, 0755, true)) {
        file_put_contents($escapeDir . 'plain.html', 'BODY');
        $evil = new TemplateEngine(new DiagnosticsCollector());
        $evil->setTemplateDir($escapeDir);
        $evil->setTemplateCacheDir($TMP . '/tplcache-evil/');
        $evil->setTemplateExtension('.html');
        $evil->setParseMode(TemplateEngine::PARSE_MODE_PLAIN);
        $out = $evil->renderTemplate('plain');
        eq('a template_dir crafted to break out of the eval\'d literal still just renders', 'BODY', $out->unwrap());
        check('…and executed nothing (var_export, not "interpolation")', !is_file($marker));
    } else {
        check('SKIPPED: filesystem rejected the hostile directory name', true);
    }

    // =========================================================================
    echo "\n3. ErrorHandler: record is not escalate\n";

    $driverDir = $TMP . '/drivers';
    mkdir($driverDir, 0755, true);

    /** Boot ErrorHandler in a child process and return everything it printed. */
    $runDriver = static function (string $body, string $requestUri) use ($ROOT, $driverDir): string {
        $prelude = <<<PHP
        <?php
        declare(strict_types=1);
        define('LANG_DIR', '{$ROOT}/resources/lang/');
        define('TEMPLATE_DIR', '{$ROOT}/resources/template/');
        \$CLASS_DIR = '{$ROOT}/src/AstrX/';
        require_once \$CLASS_DIR . 'Support/constants.php';
        spl_autoload_register(static function (string \$class) use (\$CLASS_DIR): void {
            if (strncmp(\$class, 'AstrX\\\\', 6) !== 0) { return; }
            \$file = \$CLASS_DIR . str_replace('\\\\', '/', substr(\$class, 6)) . '.php';
            if (is_file(\$file)) { require_once \$file; }
        });
        \$_SERVER['REQUEST_URI'] = '{$requestUri}';
        \$eh = new AstrX\ErrorHandler\ErrorHandler(new AstrX\Result\DiagnosticsCollector());
        \$eh->setEnvironment(AstrX\ErrorHandler\EnvironmentType::PRODUCTION);
        PHP;

        $path = $driverDir . '/driver-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, $prelude . "\n" . $body . "\n");
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($path) . ' 2>/dev/null');
        return is_string($out) ? $out : '';
    };

    // The reviewer's repro, minus the mail plumbing: a successful response, then
    // a PHP warning. The body must come back untouched.
    $warned = $runDriver(
        'echo "ATTACHMENT-BYTES";' . "\n"
        . '$fh = fopen("/definitely/not/here", "r");' . "\n"   // real E_WARNING
        . 'unset($fh);',
        '/en/webmail',
    );
    eq('an E_WARNING leaves a successful response byte-for-byte intact', 'ATTACHMENT-BYTES', $warned);
    check('…and specifically appends no error page', !str_contains($warned, '<h1>'));

    // A genuine fatal still produces an error page — and only that. Half a
    // rendered page followed by an error block is not a response anyone can use.
    $torn = $runDriver(
        'ob_start(); echo "HALF-RENDERED-PAGE"; astrx_no_such_function_at_all();',
        '/en/main',
    );
    check('a fatal discards the partial render instead of appending to it', !str_contains($torn, 'HALF-RENDERED-PAGE'));
    check('…and the error page is what the client gets', str_contains($torn, 'Internal Server Error'));

    // A genuine fatal still produces an error page.
    $fatal = $runDriver('astrx_no_such_function_at_all();', '/en/main');
    check('a real fatal still renders a 500 page', str_contains($fatal, 'Internal Server Error'));
    check('…as a complete HTML document', str_contains($fatal, '<!DOCTYPE html>'));
    check('…marked noindex, so a crawler cannot index the failure', str_contains($fatal, 'noindex'));

    // CONTRIBUTING: no user-facing string outside the Translator. The failsafe
    // now reuses the Http domain's existing http.status.500.* entries.
    $fatalIt = $runDriver('astrx_no_such_function_at_all();', '/it/main');
    check(
        'the failsafe page is translated from the request locale (it)',
        str_contains($fatalIt, 'Errore interno del server'),
    );
    check(
        '…and an unknown locale segment falls back to en instead of being echoed',
        str_contains($runDriver('astrx_no_such_function_at_all();', '/zz/main'), 'Internal Server Error'),
    );

    // =========================================================================
    echo "\n4. BoardView role colours\n";

    $cssColor = new ReflectionMethod(BoardView::class, 'cssColor');
    $colours  = [
        // shipped role_colors defaults
        'red'                           => 'red',
        'purple'                        => 'purple',
        'white'                         => 'white',
        '#fff'                          => '#fff',
        '#ffcc00'                       => '#ffcc00',
        'rebeccapurple'                 => 'rebeccapurple',
        // e() passes all of these through unchanged — none needs a quote
        'red;background:url(http://x/)' => '',
        'url(http://x/beacon.png)'      => '',
        'red;background-image:var(--x)' => '',
        'expression(alert(1))'          => '',
        '#GGG'                          => '',
    ];
    foreach ($colours as $input => $expected) {
        eq("cssColor(" . var_export($input, true) . ")", $expected, $cssColor->invoke(null, $input));
    }

    // =========================================================================
    echo "\n5. System-config validation\n";

    $_SESSION = [];

    $stub = static fn(string $class): object =>
        (new ReflectionClass($class))->newInstanceWithoutConstructor();

    $controller = (new ReflectionClass(AdminConfigSystemController::class))->newInstanceArgs([
        new DiagnosticsCollector(),
        $stub(AstrX\Template\DefaultTemplateContext::class),
        $stub(AstrX\Http\Request::class),
        $stub(AstrX\Config\Config::class),
        new ConfigWriter(),
        $stub(AstrX\Auth\Gate::class),
        $stub(AstrX\Csrf\CsrfHandler::class),
        $stub(AstrX\Session\PrgHandler::class),
        new FlashBag(),
        new Page(1, 'WORDING_ADMIN_CONFIG_SYSTEM', true, 'admin_config_system', true, true, false),
        $stub(AstrX\Routing\UrlGenerator::class),
        new AstrX\I18n\Translator(),
        $stub(AstrX\Admin\AuditLogger::class),
    ]);
    $saveTemplate = new ReflectionMethod(AdminConfigSystemController::class, 'saveTemplate');

    $goodPost = [
        'template_dir'       => $hostileDir,
        'template_cache_dir' => $cacheDir,
        'template_extension' => '.html',
        'cache_templates'    => '1',
        'parse_mode'         => '1',
    ];

    /** @param array<string,mixed> $post */
    $save = static function (array $post) use ($controller, $saveTemplate): Result {
        /** @var Result<mixed> $r */
        $r = $saveTemplate->invoke($controller, $post);
        return $r;
    };

    $rejects = [
        'a blank template_dir (would brick every render, including this page)' => ['template_dir' => ''],
        'a template_dir that does not exist'                                   => ['template_dir' => $TMP . '/nope/'],
        'a template_cache_dir whose parent does not exist'                     => ['template_cache_dir' => $TMP . '/a/b/c/'],
        'an extension with no leading dot'                                     => ['template_extension' => 'html'],
        'an extension containing a path separator'                             => ['template_extension' => '.html/../..'],
        'a parse_mode the engine does not implement'                           => ['parse_mode' => '2'],
        'a negative parse_mode'                                                => ['parse_mode' => '-1'],
    ];
    foreach ($rejects as $label => $override) {
        $r = $save(array_merge($goodPost, $override));
        check("rejects {$label}", $r->isOk() && $r->unwrap() === false);
    }
    check('nothing was written while rejecting', !is_file(CONFIG_DIR . 'TemplateEngine.config.php'));

    $ok = $save($goodPost);
    check('accepts a valid save', $ok->isOk() && $ok->unwrap() === true);

    $written = is_file(CONFIG_DIR . 'TemplateEngine.config.php')
        ? (require CONFIG_DIR . 'TemplateEngine.config.php')
        : [];
    $section = is_array($written) && isset($written['TemplateEngine']) && is_array($written['TemplateEngine'])
        ? $written['TemplateEngine'] : [];
    eq('parse_mode is stored as an int', 1, $section['parse_mode'] ?? null);
    eq('template_extension survives the round trip', '.html', $section['template_extension'] ?? null);
    check(
        'php_processing is no longer written — nothing ever read it',
        !array_key_exists('php_processing', $section),
    );

    rmTree($TMP);

    echo "\n{$PASS} passed, {$FAIL} failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
