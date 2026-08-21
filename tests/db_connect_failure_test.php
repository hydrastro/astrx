<?php
declare(strict_types=1);

/**
 * Database-connection failure path — NO AstrX bootstrap, NO database.
 *
 * ContentManager::initPDO() used to call `new PDO(...)` unguarded, with a
 * fallback password literal behind it. Two things came out of that:
 *
 *   1. Any unreachable/refusing database was an UNCAUGHT PDOException. In
 *      production that is a bare 500 with nothing an operator can read; under
 *      environment=testing it is a printed stack trace, and a PDOException's
 *      message carries the connection details — the host on a resolve failure
 *      ("getaddrinfo for db.internal failed"), the account and the calling host
 *      on an auth failure ("Access denied for user 'astrx'@'10.0.0.7'").
 *
 *   2. With no resources/config/PDO.config.php — the normal state of a fresh
 *      checkout, since the file is gitignored — the defaults 'localhost' /
 *      'user' / 'password' meant the framework quietly tried a guessable
 *      account against whatever was listening, and reported the auth failure
 *      as though the configuration had been read.
 *
 * What is asserted here:
 *   A. ConnectionFailure::classify() maps real SQLSTATE/driver codes.
 *   B. DatabaseUnavailableDiagnostic::fromException() keeps the codes and
 *      DROPS the message — including from its own private state, so a
 *      print_r()/var_export() dump of the object cannot leak it either.
 *   C. Neither locale's catalog can print a credential.
 *   D. initPDO() returns Result::err + the right diagnostic instead of
 *      throwing, for: a refused connection, an incomplete config, and a driver
 *      this PHP build does not have.
 *   E. The credentials have no fallback left in the source.
 *   F. renderError() answers with the framework's themed, DB-free page —
 *      diagnostics shown under environment=testing, hidden in production,
 *      credentials in neither.
 *
 * Run:  php tests/db_connect_failure_test.php
 */

namespace {

    use AstrX\Auth\Gate;
    use AstrX\Config\Config;
    use AstrX\ContentManager;
    use AstrX\Database\ConnectionFailure;
    use AstrX\Database\Diagnostic\DatabaseConfigIncompleteDiagnostic;
    use AstrX\Database\Diagnostic\DatabaseUnavailableDiagnostic;
    use AstrX\ErrorHandler\EnvironmentType;
    use AstrX\Http\HttpStatus;
    use AstrX\I18n\Translator;
    use AstrX\Injector\Injector;
    use AstrX\Module\ModuleLoader;
    use AstrX\Result\DiagnosticInterface;
    use AstrX\Result\DiagnosticLevel;
    use AstrX\Result\DiagnosticRenderer;
    use AstrX\Result\DiagnosticsCollector;
    use AstrX\Result\Result;
    use AstrX\User\UserSession;

    $ROOT      = dirname(__DIR__);
    $CLASS_DIR = $ROOT . '/src/AstrX/';

    require_once __DIR__ . '/lib/scratch.php';
    $TMP = AstrX\TestSupport\scratchDir('astrx-db-failure-');

    // Config comes from the scratch dir (never resources/config, which holds a
    // real install's credentials); lang and templates are the repo's own, read
    // only, so the catalogs and theme under test are the shipped ones.
    define('CONFIG_DIR',   $TMP . '/config/');
    define('LANG_DIR',     $ROOT . '/resources/lang/');
    define('TEMPLATE_DIR', $ROOT . '/resources/template/');
    mkdir(CONFIG_DIR, 0700, true);

    require_once $CLASS_DIR . 'Support/constants.php';
    spl_autoload_register(static function (string $class) use ($CLASS_DIR): void {
        if (strncmp($class, 'AstrX\\', 6) !== 0) { return; }
        $file = $CLASS_DIR . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) { require_once $file; }
    });

    // Buffer everything this test prints. Section F drives the real
    // renderError(), which starts with http_response_code() — under the CLI SAPI
    // that warns once a single byte has been written, so without a buffer the
    // progress lines above it would turn a passing assertion into a warning.
    ob_start();
    register_shutdown_function(static function (): void {
        while (ob_get_level() > 0) { ob_end_flush(); }
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

    // The values a leak would expose. Nothing this test produces may contain any
    // of them, in any locale, through any surface.
    const SECRET_HOST = 'astrx-secret-db-host.invalid';
    const SECRET_USER = 'astrx_secret_account';
    const SECRET_PASS = 'correct-horse-battery-staple';

    /** Build a PDOException shaped exactly like the ones PDO throws. */
    function pdoConnError(string $sqlState, int $driverCode, string $message): PDOException
    {
        // PDO's own message format, which is what a naive handler would echo.
        $e = new PDOException("SQLSTATE[{$sqlState}] [{$driverCode}] {$message}", $driverCode);
        $e->errorInfo = [$sqlState, $driverCode, $message];
        return $e;
    }

    /** A DiagnosticRenderer holding the repo's real catalog for one locale. */
    function rendererFor(string $locale): DiagnosticRenderer
    {
        $t = new Translator(new DiagnosticsCollector());
        $t->setLocale($locale);
        $r = new DiagnosticRenderer($t);
        $r->loadDomain(AstrX\Support\langDir(), 'Diagnostics');
        return $r;
    }

    /**
     * Every surface through which a diagnostic's contents can reach a human:
     * the catalog string in each installed locale, its __toString(), and a full
     * dump of the object graph — ErrorHandler's dev-mode handler print_r()s the
     * sink, so a private property IS a surface.
     *
     * @return list<string>
     */
    function surfacesOf(DiagnosticInterface $d): array
    {
        $out = [];
        foreach (['en', 'it'] as $locale) {
            $out[] = rendererFor($locale)->render($d);
        }
        $out[] = (string) $d;
        $out[] = var_export($d, true);
        $out[] = print_r($d, true);
        return $out;
    }

    /** Assert no surface of $d contains any credential. */
    function checkNoLeak(string $label, DiagnosticInterface $d): void
    {
        foreach (surfacesOf($d) as $i => $surface) {
            foreach ([
                'host'     => SECRET_HOST,
                'user'     => SECRET_USER,
                'password' => SECRET_PASS,
                'dsn'      => 'mysql:host=',
            ] as $what => $needle) {
                check(
                    "{$label}: surface #{$i} does not contain the {$what}",
                    !str_contains($surface, $needle),
                );
            }
        }
    }

    /** A ContentManager wired to a scratch Config — no DB, no bootstrap. */
    function managerWith(Config $config): ContentManager
    {
        $collector  = new DiagnosticsCollector();
        $translator = new Translator($collector);
        $translator->setLocale('en');

        return new ContentManager(
            new Injector(),
            $config,
            $collector,
            new ModuleLoader($config, $translator, $collector),
            $translator,
            new Gate(new UserSession()),
        );
    }

    /** Write the scratch resources/config/PDO.config.php. @param array<string,mixed> $pdo */
    function writePdoConfig(array $pdo): void
    {
        file_put_contents(
            CONFIG_DIR . 'PDO.config.php',
            "<?php\nreturn " . var_export(['PDO' => $pdo], true) . ";\n",
        );
    }

    function removePdoConfig(): void
    {
        @unlink(CONFIG_DIR . 'PDO.config.php');
    }

    /** Call the private initPDO() and hand back its Result. @return Result<PDO> */
    function initPdo(ContentManager $cm): Result
    {
        $m = new ReflectionMethod(ContentManager::class, 'initPDO');
        /** @var Result<PDO> $r */
        $r = $m->invoke($cm);
        return $r;
    }

    /** @return list<DiagnosticInterface> */
    function diagList(Result $r): array
    {
        return $r->diagnostics()->toArray();
    }

    // A base config.php for every case; per-case values are merged over it.
    function writeBaseConfig(int $environment): void
    {
        file_put_contents(CONFIG_DIR . 'config.php', "<?php\nreturn " . var_export([
            'Prelude'        => ['environment' => $environment, 'default_language' => 'en'],
            // Deliberately markup-bearing: the failsafe page has no template
            // engine, so it must escape what it interpolates itself.
            'ContentManager' => ['website_name' => 'Test <Site> & Co'],
        ], true) . ";\n");
    }

    // =========================================================================
    // A. Classification of the codes
    // =========================================================================

    echo "\n== ConnectionFailure::classify() ==\n";

    eq('MySQL 2002 (cannot connect) is UNREACHABLE',
        ConnectionFailure::UNREACHABLE, ConnectionFailure::classify('HY000', 2002));
    eq('MySQL 2005 (unknown host) is UNREACHABLE',
        ConnectionFailure::UNREACHABLE, ConnectionFailure::classify('HY000', 2005));
    eq('MySQL 1045 (access denied) is AUTH_REJECTED',
        ConnectionFailure::AUTH_REJECTED, ConnectionFailure::classify('28000', 1045));
    eq('MySQL 1044 (db access denied) is AUTH_REJECTED',
        ConnectionFailure::AUTH_REJECTED, ConnectionFailure::classify('42000', 1044));
    eq('MySQL 1049 (unknown database) is UNKNOWN_DATABASE',
        ConnectionFailure::UNKNOWN_DATABASE, ConnectionFailure::classify('42000', 1049));
    eq('SQLSTATE 28P01 (bad password) is AUTH_REJECTED without a known driver code',
        ConnectionFailure::AUTH_REJECTED, ConnectionFailure::classify('28P01', 7));
    eq('SQLSTATE 3D000 (invalid catalog) is UNKNOWN_DATABASE',
        ConnectionFailure::UNKNOWN_DATABASE, ConnectionFailure::classify('3D000', 7));
    eq('SQLSTATE class 08 is UNREACHABLE',
        ConnectionFailure::UNREACHABLE, ConnectionFailure::classify('08006', 7));
    eq('an unrecognised pair is UNKNOWN, not a guess',
        ConnectionFailure::UNKNOWN, ConnectionFailure::classify('HY000', 4242));

    // =========================================================================
    // B. fromException() keeps the codes and drops the message
    // =========================================================================

    echo "\n== DatabaseUnavailableDiagnostic::fromException() ==\n";

    // The two messages that actually leak, verbatim from PDO.
    $resolveFailure = pdoConnError('HY000', 2002,
        'php_network_getaddresses: getaddrinfo for ' . SECRET_HOST . ' failed: Name or service not known');
    $authFailure = pdoConnError('28000', 1045,
        "Access denied for user '" . SECRET_USER . "'@'10.0.0.7' (using password: YES)");

    $resolveDiag = DatabaseUnavailableDiagnostic::fromException(
        ContentManager::ID_DB_UNAVAILABLE, ContentManager::LVL_DB_UNAVAILABLE, 'mysql', $resolveFailure);
    $authDiag = DatabaseUnavailableDiagnostic::fromException(
        ContentManager::ID_DB_UNAVAILABLE, ContentManager::LVL_DB_UNAVAILABLE, 'mysql', $authFailure);

    eq('the resolve failure keeps its SQLSTATE',   'HY000', $resolveDiag->sqlState());
    eq('the resolve failure keeps its driver code', 2002,   $resolveDiag->driverCode());
    eq('the resolve failure is classified UNREACHABLE',
        ConnectionFailure::UNREACHABLE, $resolveDiag->reason());
    eq('the auth failure keeps its SQLSTATE',      '28000', $authDiag->sqlState());
    eq('the auth failure keeps its driver code',    1045,   $authDiag->driverCode());
    eq('the auth failure is classified AUTH_REJECTED',
        ConnectionFailure::AUTH_REJECTED, $authDiag->reason());
    eq('the level is CRITICAL — nothing in the request can proceed',
        DiagnosticLevel::CRITICAL, $authDiag->level());

    // A message-only PDOException (PDO's own failures carry no errorInfo).
    $driverless = new PDOException('could not find driver');
    $driverlessDiag = DatabaseUnavailableDiagnostic::fromException(
        ContentManager::ID_DB_UNAVAILABLE, ContentManager::LVL_DB_UNAVAILABLE, 'mysql', $driverless);
    eq('a message-only exception yields no invented SQLSTATE', '', $driverlessDiag->sqlState());
    eq('a message-only exception yields no invented driver code', 0, $driverlessDiag->driverCode());

    checkNoLeak('resolve failure', $resolveDiag);
    checkNoLeak('auth failure',    $authDiag);

    // =========================================================================
    // C. Both catalogs resolve, and say something useful
    // =========================================================================

    echo "\n== Diagnostics catalogs (en + it) ==\n";

    foreach (['en', 'it'] as $locale) {
        $renderer = rendererFor($locale);

        foreach ([
            'connect_failed'    => $authDiag,
            'config_incomplete' => new DatabaseConfigIncompleteDiagnostic(
                ContentManager::ID_DB_CONFIG_INCOMPLETE,
                ContentManager::LVL_DB_CONFIG_INCOMPLETE,
                ['db_username', 'db_password'],
            ),
        ] as $what => $diag) {
            $text = $renderer->render($diag);
            check("[{$locale}] {$what} resolves to a real message, not a [FALLBACK:…] stamp",
                !str_contains($text, 'FALLBACK') && $text !== '');
            check("[{$locale}] {$what} names PDO.config.php so the operator knows where to look",
                str_contains($text, 'PDO.config.php'));
        }

        $auth = $renderer->render($authDiag);
        check("[{$locale}] the auth failure reports its SQLSTATE",   str_contains($auth, '28000'));
        check("[{$locale}] the auth failure reports its driver code", str_contains($auth, '1045'));

        $incomplete = $renderer->render(new DatabaseConfigIncompleteDiagnostic(
            ContentManager::ID_DB_CONFIG_INCOMPLETE,
            ContentManager::LVL_DB_CONFIG_INCOMPLETE,
            ['db_username', 'db_password'],
        ));
        check("[{$locale}] the incomplete config names every missing key",
            str_contains($incomplete, 'db_username') && str_contains($incomplete, 'db_password'));
    }

    // =========================================================================
    // D. initPDO() returns a Result instead of throwing
    // =========================================================================

    echo "\n== initPDO() on a database that will not answer ==\n";

    writeBaseConfig(EnvironmentType::TESTING->value);

    // 127.0.0.1:1 is closed and needs no DNS, so this exercises a REAL
    // PDOException from a REAL connection attempt without leaving the loopback.
    writePdoConfig([
        'db_type'             => 'mysql',
        'db_host'             => '127.0.0.1',
        'db_name'             => 'astrx_test_db',
        'db_port'             => 1,
        'db_username'         => SECRET_USER,
        'db_password'         => SECRET_PASS,
        'emulate_prepares'    => false,
        'errmode_exception'   => true,
        'default_fetch_assoc' => true,
    ]);

    $config = new Config(new DiagnosticsCollector());
    $config->loadModuleConfig('PDO');
    $refused = initPdo(managerWith($config));

    check('a refused connection returns an err Result rather than throwing', $refused->isErr());
    $refusedDiags = diagList($refused);
    eq('it carries exactly one diagnostic', 1, count($refusedDiags));
    $refusedDiag = $refusedDiags[0] ?? null;
    check('the diagnostic is a DatabaseUnavailableDiagnostic',
        $refusedDiag instanceof DatabaseUnavailableDiagnostic);
    if ($refusedDiag instanceof DatabaseUnavailableDiagnostic) {
        eq('with the connect_failed id', ContentManager::ID_DB_UNAVAILABLE, $refusedDiag->id());
        eq('at CRITICAL',                DiagnosticLevel::CRITICAL,         $refusedDiag->level());
        eq('naming the configured driver', 'mysql',                         $refusedDiag->driver());
        checkNoLeak('refused connection', $refusedDiag);
    }

    echo "\n== initPDO() with an incomplete PDO.config.php ==\n";

    removePdoConfig();
    $config = new Config(new DiagnosticsCollector());
    $config->loadModuleConfig('PDO');
    $noConfig = initPdo(managerWith($config));

    check('a missing PDO section returns an err Result', $noConfig->isErr());
    $noConfigDiags = diagList($noConfig);
    $noConfigDiag  = $noConfigDiags[0] ?? null;
    check('the diagnostic is a DatabaseConfigIncompleteDiagnostic',
        $noConfigDiag instanceof DatabaseConfigIncompleteDiagnostic);
    if ($noConfigDiag instanceof DatabaseConfigIncompleteDiagnostic) {
        eq('with the config_incomplete id',
            ContentManager::ID_DB_CONFIG_INCOMPLETE, $noConfigDiag->id());
        eq('naming every required key, in order',
            ['db_host', 'db_name', 'db_username', 'db_password'], $noConfigDiag->missingKeys());
    }
    // The point of the named failure: NOTHING was dialled. A connect_failed here
    // would mean the old behaviour — a guessed credential tried against
    // whatever answers on localhost — had come back.
    foreach ($noConfigDiags as $d) {
        check('no connection was attempted for an unconfigured database',
            !$d instanceof DatabaseUnavailableDiagnostic);
    }

    // One key absent is the same failure as all of them absent.
    writePdoConfig([
        'db_type'             => 'mysql',
        'db_host'             => SECRET_HOST,
        'db_name'             => 'astrx_test_db',
        'db_port'             => 3306,
        'db_username'         => SECRET_USER,
        // db_password deliberately absent
        'emulate_prepares'    => false,
        'errmode_exception'   => true,
        'default_fetch_assoc' => true,
    ]);
    $config = new Config(new DiagnosticsCollector());
    $config->loadModuleConfig('PDO');
    $noPass = initPdo(managerWith($config));
    $noPassDiag = diagList($noPass)[0] ?? null;
    check('an absent db_password alone is a named failure, not a guessed password',
        $noPassDiag instanceof DatabaseConfigIncompleteDiagnostic);
    if ($noPassDiag instanceof DatabaseConfigIncompleteDiagnostic) {
        eq('naming exactly the missing key', ['db_password'], $noPassDiag->missingKeys());
        checkNoLeak('absent password', $noPassDiag);
    }

    // An EMPTY password is a legitimate configured value and must still connect.
    writePdoConfig([
        'db_type'             => 'mysql',
        'db_host'             => '127.0.0.1',
        'db_name'             => 'astrx_test_db',
        'db_port'             => 1,
        'db_username'         => SECRET_USER,
        'db_password'         => '',
        'emulate_prepares'    => false,
        'errmode_exception'   => true,
        'default_fetch_assoc' => true,
    ]);
    $config = new Config(new DiagnosticsCollector());
    $config->loadModuleConfig('PDO');
    $emptyPass = initPdo(managerWith($config));
    check('an empty password is a value, not an absence — the connection is attempted',
        (diagList($emptyPass)[0] ?? null) instanceof DatabaseUnavailableDiagnostic);

    echo "\n== initPDO() with a driver this PHP build does not have ==\n";

    writePdoConfig([
        'db_type'             => 'astrx_no_such_driver',
        'db_host'             => SECRET_HOST,
        'db_name'             => 'astrx_test_db',
        'db_port'             => 3306,
        'db_username'         => SECRET_USER,
        'db_password'         => SECRET_PASS,
        'emulate_prepares'    => false,
        'errmode_exception'   => true,
        'default_fetch_assoc' => true,
    ]);
    $config = new Config(new DiagnosticsCollector());
    $config->loadModuleConfig('PDO');
    $noDriver = initPdo(managerWith($config));
    $noDriverDiag = diagList($noDriver)[0] ?? null;
    check('an unavailable driver is reported as such, not attempted',
        $noDriverDiag instanceof DatabaseUnavailableDiagnostic);
    if ($noDriverDiag instanceof DatabaseUnavailableDiagnostic) {
        eq('classified DRIVER_MISSING', ConnectionFailure::DRIVER_MISSING, $noDriverDiag->reason());
        checkNoLeak('missing driver', $noDriverDiag);
    }

    // =========================================================================
    // E. The source itself: no credential may have a fallback
    // =========================================================================

    echo "\n== initPDO() source contract ==\n";

    $rm    = new ReflectionMethod(ContentManager::class, 'initPDO');
    $lines = file((string) $rm->getFileName()) ?: [];
    // Comment lines are dropped: the method's comments QUOTE the removed
    // defaults to explain why they are gone, and a check that cannot tell a
    // fallback from the note about it is worth nothing.
    $body  = implode('', array_filter(
        array_slice($lines, $rm->getStartLine() - 1, $rm->getEndLine() - $rm->getStartLine() + 1),
        static fn(string $line): bool => !str_starts_with(ltrim($line), '//'),
    ));

    eq('initPDO() returns a Result, so failure has somewhere to go',
        'AstrX\Result\Result', (string) $rm->getReturnType());
    check("the fallback password literal 'password' is gone",
        !str_contains($body, "'password'"));
    check("the fallback username literal 'user' is gone",
        !str_contains($body, "'user'"));
    check('every credential is read WITHOUT a default',
        preg_match(
            "/getConfig[A-Za-z]*\(\s*'PDO'\s*,\s*'db_(host|name|username|password)'\s*,/",
            $body,
        ) === 0);
    check('the PDO constructor is inside a try block',
        preg_match('/try\s*\{[^}]*new PDO\(/s', $body) === 1);

    // =========================================================================
    // F. The page a visitor gets when the database is down
    // =========================================================================

    echo "\n== renderError(): the themed, database-free failsafe ==\n";

    /** Render the failsafe for one environment, with the DB diagnostic collected. */
    function failsafeHtml(int $environment): string
    {
        writeBaseConfig($environment);
        removePdoConfig();

        $collector  = new DiagnosticsCollector();
        $translator = new Translator($collector);
        $translator->setLocale('en');
        $config = new Config($collector);

        $cm = new ContentManager(
            new Injector(),
            $config,
            $collector,
            new ModuleLoader($config, $translator, $collector),
            $translator,
            new Gate(new UserSession()),
        );

        // The exact diagnostic initPDO() emits for a rejected credential.
        $collector->emit(DatabaseUnavailableDiagnostic::fromException(
            ContentManager::ID_DB_UNAVAILABLE,
            ContentManager::LVL_DB_UNAVAILABLE,
            'mysql',
            pdoConnError('28000', 1045,
                "Access denied for user '" . SECRET_USER . "'@'10.0.0.7' (using password: YES)"),
        ));

        $m = new ReflectionMethod(ContentManager::class, 'renderError');
        ob_start();
        $m->invoke($cm, HttpStatus::SERVICE_UNAVAILABLE);
        return (string) ob_get_clean();
    }

    $testingHtml = failsafeHtml(EnvironmentType::TESTING->value);
    $prodHtml    = failsafeHtml(EnvironmentType::PRODUCTION->value);

    foreach (['testing' => $testingHtml, 'production' => $prodHtml] as $env => $html) {
        check("[{$env}] the response is an HTML document, not a blank page",
            str_starts_with($html, '<!DOCTYPE html>'));
        check("[{$env}] it is the framework's page structure, not a browser default",
            str_contains($html, '<div id="wrap">') && str_contains($html, '<div id="main">'));
        check("[{$env}] the active theme's stylesheet is inlined",
            str_contains($html, '<style>') && str_contains($html, '#wrap'));
        check("[{$env}] the status name comes from the Http catalog",
            str_contains($html, '503') && str_contains($html, 'Service Unavailable'));
        check("[{$env}] the site name is escaped, not interpolated raw",
            str_contains($html, 'Test &lt;Site&gt; &amp; Co') && !str_contains($html, 'Test <Site>'));
        check("[{$env}] search engines are told not to index the failure",
            str_contains($html, 'noindex,nofollow'));
        check("[{$env}] no stack trace reaches the browser",
            !str_contains($html, 'PDOException') && !str_contains($html, '#0 '));

        foreach (['host' => SECRET_HOST, 'user' => SECRET_USER, 'password' => SECRET_PASS] as $what => $needle) {
            check("[{$env}] the {$what} does not appear anywhere in the response",
                !str_contains($html, $needle));
        }
    }

    check('[testing] the operator can read what went wrong',
        str_contains($testingHtml, 'rejected the configured credentials')
        && str_contains($testingHtml, '1045'));
    check('[production] a visitor learns nothing beyond the status',
        !str_contains($prodHtml, 'rejected the configured credentials')
        && !str_contains($prodHtml, '1045')
        && !str_contains($prodHtml, 'PDO.config.php'));

    // =========================================================================
    // G. public/setup.php runs the same migrations — and needs the same rule
    // =========================================================================

    echo "\n== setup.php runSQL() error classification ==\n";

    /** A PDOException shaped like the ones a failing STATEMENT throws: code = SQLSTATE. */
    function pdoStmtError(string $sqlState, int $driverCode): PDOException
    {
        $e = new PDOException('test');
        $e->errorInfo = [$sqlState, $driverCode, 'test'];
        $prop = new ReflectionProperty(Exception::class, 'code');
        $prop->setAccessible(true);
        $prop->setValue($e, $sqlState);
        return $e;
    }

    // setup.php is a web entry point — requiring it would run the wizard — and
    // phpstan.neon excludes it, so this is the only gate its SQL classifier has.
    // Lift the pure function out, the way setup_tooling_test.php lifts
    // install.php's.
    $setupSrc  = (string) file_get_contents($ROOT . '/public/setup.php');
    $sliceFrom = strpos($setupSrc, 'function sqlErrorIsBenign(');
    $sliceTo   = strpos($setupSrc, 'function ensureMigrationTable(');
    check('setup.php defines sqlErrorIsBenign() ahead of ensureMigrationTable()',
        $sliceFrom !== false && $sliceTo !== false && $sliceTo > $sliceFrom);

    if ($sliceFrom !== false && $sliceTo !== false && $sliceTo > $sliceFrom) {
        eval(substr($setupSrc, $sliceFrom, $sliceTo - $sliceFrom));
    }

    if (function_exists('sqlErrorIsBenign')) {
        check('42S01 (table already exists) is benign — the wizard stays re-runnable',
            sqlErrorIsBenign(pdoStmtError('42S01', 1050)));
        check('42S21 (column already exists) is benign',
            sqlErrorIsBenign(pdoStmtError('42S21', 1060)));
        check('23000/1062 (duplicate entry) is benign',
            sqlErrorIsBenign(pdoStmtError('23000', 1062)));
        check('23000/1022 (duplicate key) is benign',
            sqlErrorIsBenign(pdoStmtError('23000', 1022)));

        // The regression the CLI installer already fixed: a migration whose
        // INSERT violates integrity must NOT be recorded as applied.
        check('23000/1452 (foreign key violation) is NOT benign',
            !sqlErrorIsBenign(pdoStmtError('23000', 1452)));
        check('23000/1451 (foreign key violation on delete) is NOT benign',
            !sqlErrorIsBenign(pdoStmtError('23000', 1451)));
        check('23000/1048 (column cannot be null) is NOT benign',
            !sqlErrorIsBenign(pdoStmtError('23000', 1048)));
        check('a 23000 with no driver code is NOT benign',
            !sqlErrorIsBenign(pdoStmtError('23000', 0)));
        check('42000 (parse/access error) is NOT benign',
            !sqlErrorIsBenign(pdoStmtError('42000', 1064)));
    }

    check('setup.php no longer swallows the whole of SQLSTATE 23000',
        !str_contains($setupSrc, "['42S01','42S21','23000']"));

    // =========================================================================

    echo "\n$PASS passed, $FAIL failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
