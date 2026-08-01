<?php
declare(strict_types=1);

/**
 * AstrX data-retention runner — `php tools/retention.php [run]`
 *
 * Applies every configured retention window (the same thing the admin "Run
 * retention now" button does): age-based targets are purged past their window,
 * expiry-based chat tables run their GC. Intended for cron.
 *
 *   run            Apply retention now (default).
 *   --help, -h     Show this help.
 *
 * Cron (hourly):
 *   0 * * * * php /path/to/tools/retention.php run
 */

use AstrX\Chat\ChatConfig;
use AstrX\Config\Config;
use AstrX\Imageboard\ImageboardConfig;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Retention\RetentionService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

$root = dirname(__DIR__);

if (!defined('INDEX_DIR'))     { define('INDEX_DIR', $root . DIRECTORY_SEPARATOR); }
if (!defined('RESOURCES_DIR')) { define('RESOURCES_DIR', INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR); }
if (!defined('CONFIG_DIR'))    { define('CONFIG_DIR', RESOURCES_DIR . 'config' . DIRECTORY_SEPARATOR); }
if (!defined('SRC_DIR'))       { define('SRC_DIR', INDEX_DIR . 'src' . DIRECTORY_SEPARATOR); }
if (!defined('CLASS_DIR'))     { define('CLASS_DIR', SRC_DIR . 'AstrX' . DIRECTORY_SEPARATOR); }

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

function rt_out(string $s): void { fwrite(STDOUT, $s); }
function rt_err(string $s): void { fwrite(STDERR, $s); }
/** @return never */
function rt_fail(string $msg): void { rt_err("\nERROR: {$msg}\n"); exit(1); }

/** Build a PDO from the persisted PDO.config.php, mirroring ContentManager::initPDO. */
function rt_build_pdo(Config $config): PDO
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

/** @var list<string> $argv */
$argv = $argv ?? [];

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    rt_out("AstrX data-retention runner\n\n  php tools/retention.php run\n\nCron (hourly):\n  0 * * * * php {$root}/tools/retention.php run\n");
    exit(0);
}

if (!extension_loaded('pdo_mysql')) {
    rt_fail('The pdo_mysql extension is required but not loaded.');
}

$collector = new DiagnosticsCollector();
$config    = new Config($collector);
$config->loadModuleConfig('PDO');

try {
    $pdo = rt_build_pdo($config);
} catch (PDOException $e) {
    rt_fail('database connection failed: ' . $e->getMessage());
}

// Configure the imageboard/chat configs (their upload dirs drive the orphan-file
// reaper) exactly as the module loader does in the web app: load the domain, then
// apply its InjectConfig setters. If a config file is absent the defaults stand.
// loadModuleConfig names the FILE (Imageboard.config.php), but applyModuleConfig
// names the SECTION inside it — which is the class short name, not the file base
// (Imageboard.config.php holds ['ImageboardConfig' => [...]]). Using the file name
// here would silently no-op and leave the configs at their defaults, so the cron
// reaper would scan the wrong dir on any deployment with a custom upload_dir.
$config->loadModuleConfig('Imageboard');
$config->loadModuleConfig('Chat');
$imageboardConfig = new ImageboardConfig();
$config->applyModuleConfig($imageboardConfig, 'ImageboardConfig');
$chatConfig = new ChatConfig();
$config->applyModuleConfig($chatConfig, 'ChatConfig');

$service = new RetentionService($pdo, $imageboardConfig, $chatConfig);
$counts  = $service->runAll();
$total   = array_sum($counts);
foreach ($counts as $key => $n) {
    rt_out('retention: ' . $key . ' → ' . $n . " row(s) removed\n");
}
rt_out('retention: done, ' . $total . " row(s) removed total.\n");
exit(0);
