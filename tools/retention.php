<?php
declare(strict_types=1);

/**
 * AstrX data-retention runner — `php tools/retention.php [run]`
 *
 * Applies every configured retention window (the same thing the admin "Run
 * retention now" button does): age-based targets are purged past their window,
 * expiry-based tables run their GC, and uploads no row references any more are
 * reaped. The targets come from the installed modules. Intended for cron.
 *
 *   run            Apply retention now (default).
 *   --help, -h     Show this help.
 *
 * Cron (hourly):
 *   0 * * * * php /path/to/tools/retention.php run
 */

use AstrX\ApplicationContainer;
use AstrX\Config\Config;
use AstrX\Injector\Injector;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Retention\RetentionService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

$root = dirname(__DIR__);

require_once $root . '/src/paths.php';
require_once $root . '/src/autoload.php';

function rt_out(string $s): void { fwrite(STDOUT, $s); }
function rt_err(string $s): void { fwrite(STDERR, $s); }
/** @return never */
function rt_fail(string $msg): void { rt_err("\nERROR: {$msg}\n"); exit(1); }

/**
 * Open the database exactly as the web request does (Database\ConnectionFactory):
 * no guessable credential defaults. On failure, exit naming what went wrong,
 * without echoing the DSN or the account.
 */
function rt_build_pdo(Config $config): PDO
{
    $result = (new \AstrX\Database\ConnectionFactory($config))->connect();
    if ($result->isOk()) {
        $pdo = $result->unwrap();
        \assert($pdo instanceof PDO);
        return $pdo;
    }
    $why = [];
    foreach ($result->diagnostics() as $d) {
        if ($d instanceof \AstrX\Database\Diagnostic\DatabaseConfigIncompleteDiagnostic) {
            $why[] = 'PDO.config.php is missing ' . implode(', ', $d->missingKeys());
        } elseif ($d instanceof \AstrX\Database\Diagnostic\DatabaseUnavailableDiagnostic) {
            $why[] = $d->driver() . ' ' . $d->reason()->name
                . ($d->sqlState() !== '' ? ' (SQLSTATE ' . $d->sqlState() . ')' : '');
        } else {
            $why[] = $d->id();
        }
    }
    rt_fail('database connection failed: ' . implode('; ', $why));
}

/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function rt_service(Injector $injector, string $class): object
{
    $result   = $injector->getClass($class);
    $instance = $result->isOk() ? $result->unwrap() : null;
    if (!$instance instanceof $class) {
        rt_fail("could not build {$class}.");
    }
    return $instance;
}

/** @var list<string> $argv */
$argv = $argv ?? [];

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    rt_out("AstrX data-retention runner\n\n  php tools/retention.php run\n\nCron (hourly):\n  0 * * * * php {$root}/tools/retention.php run\n");
    exit(0);
}

if (!extension_loaded('pdo_mysql')) {
    rt_fail('The pdo_mysql extension is required but not loaded.');
}

// The site's own configuration, translations and installed modules: which
// data is kept for how long — and where uploads live — is whatever the modules
// that keep it say (Retention\RetentionSource), configured as the site is.
$collector = new DiagnosticsCollector();
$injector  = ApplicationContainer::build($collector);
$config    = rt_service($injector, Config::class);
$config->loadModuleConfig('PDO');

$injector->setClass(rt_build_pdo($config));
$service = rt_service($injector, RetentionService::class);

$counts  = $service->runAll();
$total   = array_sum($counts);
foreach ($counts as $key => $n) {
    rt_out('retention: ' . $key . ' → ' . $n . " row(s) removed\n");
}
rt_out('retention: done, ' . $total . " row(s) removed total.\n");
exit(0);
