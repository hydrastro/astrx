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
 * Bootstrap mirrors tools/warm-template-cache.php: define the path constants,
 * register the PSR-4 autoloader, then build the handful of services the indexer
 * needs (PDO + UrlGenerator + Translator, wrapped in SearchSources) directly,
 * without booting the web request pipeline.
 */

use AstrX\Config\Config;
use AstrX\I18n\Translator;
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

if (!defined('INDEX_DIR'))          { define('INDEX_DIR', $root . DIRECTORY_SEPARATOR); }
if (!defined('RESOURCES_DIR'))      { define('RESOURCES_DIR', INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR); }
if (!defined('LANG_DIR'))           { define('LANG_DIR', RESOURCES_DIR . 'lang' . DIRECTORY_SEPARATOR); }
if (!defined('CONFIG_DIR'))         { define('CONFIG_DIR', RESOURCES_DIR . 'config' . DIRECTORY_SEPARATOR); }
if (!defined('TEMPLATE_DIR'))       { define('TEMPLATE_DIR', RESOURCES_DIR . 'template' . DIRECTORY_SEPARATOR); }
if (!defined('TEMPLATE_CACHE_DIR')) { define('TEMPLATE_CACHE_DIR', TEMPLATE_DIR . 'cache' . DIRECTORY_SEPARATOR); }
if (!defined('SRC_DIR'))            { define('SRC_DIR', INDEX_DIR . 'src' . DIRECTORY_SEPARATOR); }
if (!defined('CLASS_DIR'))          { define('CLASS_DIR', SRC_DIR . 'AstrX' . DIRECTORY_SEPARATOR); }

spl_autoload_register(static function (string $class): void {
    $prefix = 'AstrX\\';
    $len = strlen($prefix);
    if (strncmp($class, $prefix, $len) !== 0) {
        return;
    }
    $classDir = (string) constant('CLASS_DIR');
    $file = $classDir . str_replace('\\', '/', substr($class, $len)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$supportConstants = (string) constant('CLASS_DIR') . 'Support/constants.php';
if (is_file($supportConstants)) {
    require_once $supportConstants;
}

// ─────────────────────────────────────────────────────────────────────────────
// Tiny CLI helpers
// ─────────────────────────────────────────────────────────────────────────────

function sx_out(string $s): void { fwrite(STDOUT, $s); }
function sx_err(string $s): void { fwrite(STDERR, $s); }
function sx_fail(string $msg): never { sx_err("\nERROR: {$msg}\n"); exit(1); }

/** Build a PDO from the persisted PDO.config.php, mirroring ContentManager::initPDO. */
function sx_build_pdo(Config $config): PDO
{
    $driver = $config->getConfigString('PDO', 'db_type', 'mysql');
    $host   = $config->getConfigString('PDO', 'db_host', 'localhost');
    $dbname = $config->getConfigString('PDO', 'db_name', 'content_manager');
    $user   = $config->getConfigString('PDO', 'db_username', 'root');
    $pass   = $config->getConfigString('PDO', 'db_password', '');
    $port   = $config->getConfigInt('PDO', 'db_port', 3306);

    $dsn = $driver . ':host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, $pass);

    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $config->getConfigBool('PDO', 'emulate_prepares', false));
    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        $config->getConfigBool('PDO', 'errmode_exception', true) ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT,
    );
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
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
$config    = new Config($collector);
$config->loadModuleConfig('Routing');
$config->loadModuleConfig('PDO');

try {
    $pdo = sx_build_pdo($config);
} catch (PDOException $e) {
    sx_fail('database connection failed: ' . $e->getMessage());
}

// Gate the cron path: do nothing unless a rebuild was explicitly requested.
if ($ifRequested && sx_job_status($pdo) !== 'requested') {
    sx_out("search_index: nothing requested; nothing to do.\n");
    exit(0);
}

// Locale + page-slug translations so indexed URLs match what the site serves.
$locale = $config->getConfigString('Prelude', 'default_language', 'en');

$translator = new Translator($collector);
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
