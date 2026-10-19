<?php
declare(strict_types=1);

/**
 * AstrX search index crawler — `php tools/search_index.php`
 *
 * A zero-dependency CLI that (re)builds the MySQL/MariaDB FULLTEXT `search_index`
 * table from the site's public content (news, pages, comments, imageboard
 * posts). It runs OUTSIDE the web docroot and is the single crawl entry point
 * shared by all three run modes:
 *
 *   php tools/search_index.php                 Rebuild the index now.
 *   php tools/search_index.php --if-requested  Rebuild ONLY if the admin panel
 *                                              has queued a request (for cron);
 *                                              otherwise print a notice and exit 0.
 *   php tools/search_index.php --help          Show usage.
 *
 * The admin "Rebuild now" button exec()s this same script in the background.
 *
 * Bootstrap: the path constants and the autoloader, then the application's
 * own container (AstrX\ApplicationContainer — the site's configuration and the
 * installed modules' translations, so a module page's URL slug is the one the
 * site serves), plus the handful of services the indexer needs (PDO +
 * UrlGenerator, wrapped in SearchSources), without booting the web request
 * pipeline.
 */

use AstrX\ApplicationContainer;
use AstrX\Config\Config;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\UrlGenerator;
use AstrX\Search\SearchIndexer;
use AstrX\Search\SearchSources;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This crawler runs on the command line only.\n");
}

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap: constants + autoloader (no web request pipeline)
// ─────────────────────────────────────────────────────────────────────────────

$root = dirname(__DIR__);

require_once $root . '/src/paths.php';
require_once $root . '/src/autoload.php';

// ─────────────────────────────────────────────────────────────────────────────
// Tiny CLI helpers
// ─────────────────────────────────────────────────────────────────────────────

function sx_out(string $s): void { fwrite(STDOUT, $s); }
function sx_err(string $s): void { fwrite(STDERR, $s); }
function sx_fail(string $msg): never { sx_err("\nERROR: {$msg}\n"); exit(1); }

/**
 * Open the database exactly as the web request does (Database\ConnectionFactory):
 * no guessable credential defaults. On failure, exit naming what went wrong,
 * without echoing the DSN or the account.
 */
function sx_build_pdo(Config $config): PDO
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
    sx_fail('database connection failed: ' . implode('; ', $why));
}

/**
 * @template T of object
 * @param class-string<T> $class
 * @return T
 */
function sx_service(Injector $injector, string $class): object
{
    $result   = $injector->getClass($class);
    $instance = $result->isOk() ? $result->unwrap() : null;
    if (!$instance instanceof $class) {
        sx_fail("could not build {$class}.");
    }
    return $instance;
}

/** Current job status ('idle'|'requested'|'running'), or 'idle' if unreadable. */
function sx_job_status(PDO $pdo): string
{
    try {
        $stmt = $pdo->query('SELECT `status` FROM `search_index_job` WHERE `id` = 1 LIMIT 1');
        if ($stmt === false) {
            return 'idle';
        }
        $v = $stmt->fetchColumn();
        return is_string($v) ? $v : 'idle';
    } catch (Throwable) {
        return 'idle';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────────────

/** @var list<string> $argv */
$argv = $argv ?? [];

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    sx_out(<<<TXT
        AstrX search index crawler

        Usage:
          php tools/search_index.php [--if-requested]

          (no options)      Rebuild the search index immediately.
          --if-requested    Rebuild only if the admin panel queued a request
                            (intended for cron); otherwise exit 0 with a notice.
          --help, -h        Show this help.

        Cron (every 15 minutes, only when requested):
          */15 * * * * php {$root}/tools/search_index.php --if-requested

        TXT);
    exit(0);
}

$ifRequested = in_array('--if-requested', $argv, true);

if (!extension_loaded('pdo_mysql')) {
    sx_fail('The pdo_mysql extension is required but not loaded.');
}

$collector = new DiagnosticsCollector();
$injector  = ApplicationContainer::build($collector);
$config    = sx_service($injector, Config::class);
$config->loadModuleConfig('Routing');
$config->loadModuleConfig('PDO');

$pdo = sx_build_pdo($config);

// Gate the cron path: do nothing unless a rebuild was explicitly requested.
if ($ifRequested && sx_job_status($pdo) !== 'requested') {
    sx_out("search_index: nothing requested; nothing to do.\n");
    exit(0);
}

// Locale + page-slug translations so indexed URLs match what the site serves.
$locale = $config->getConfigString('Prelude', 'default_language', 'en');

$translator = sx_service($injector, Translator::class);
$translator->setLocale($locale);
$translator->loadDomain((string) constant('LANG_DIR'), 'pages');

$currentUrl = new CurrentUrl();
$currentUrl->set($config->getConfigString('Routing', 'locale_key', 'lang'), $locale);

$urlGen  = new UrlGenerator($config, $currentUrl);
$sources = new SearchSources($pdo, $urlGen, $translator);
$indexer = new SearchIndexer($pdo, $sources);

$before = $indexer->status();
sx_out('search_index: starting rebuild (was: ' . $before['status']
    . ', ' . $before['live_count'] . " indexed document(s))...\n");

$result = $indexer->rebuild(static function (string $line): void {
    sx_out('search_index: ' . $line . "\n");
})->drainTo($collector);

if ($result->isOk()) {
    sx_out('search_index: done — indexed ' . $result->unwrap() . " document(s).\n");
    exit(0);
}

$after = $indexer->status();
sx_err('search_index: rebuild FAILED — ' . ($after['message'] !== '' ? $after['message'] : 'see diagnostics') . "\n");
exit(1);
