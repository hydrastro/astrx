<?php
declare(strict_types=1);

/**
 * public/info.php — phpinfo() gated behind an authenticated ADMIN session.
 *
 * Previously this file was literally `<?=phpinfo();?>`, exposing the full PHP
 * configuration (loaded modules, paths, environment, credentials in some setups)
 * to any anonymous visitor. It now boots ONLY the minimal slice of the AstrX
 * framework needed to authenticate the caller — the PSR-4 autoloader plus the
 * DB-backed SecureSessionHandler, exactly as public/index.php wires them — and
 * verifies the caller is a logged-in administrator. Everyone else gets a 404,
 * so the endpoint's very existence stays hidden.
 *
 * Fail-closed: any missing config, DB error, or session failure results in 404.
 */

use AstrX\Session\SecureSessionHandler;
use AstrX\User\UserGroup;
use AstrX\User\UserSession;

// Minimal 404 used for every non-admin / error path. Never reveals the endpoint.
$deny = static function (): void {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head>'
       . '<body><h1>404 Not Found</h1></body></html>';
    exit;
};

// Coerce a mixed config value to a scalar without tripping level-10 casts.
$asStr = static fn(mixed $v, string $default = ''): string => is_scalar($v) ? (string) $v : $default;
$asInt = static fn(mixed $v, int $default = 0): int => is_int($v) ? $v : (is_numeric($v) ? (int) $v : $default);

// ── Minimal boot: only the dir constants index.php defines ────────────────────
if (!defined('INDEX_DIR')) {
    $root = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
    if ($root === false) { $deny(); }
    define('INDEX_DIR', $root . DIRECTORY_SEPARATOR);
}
if (!defined('RESOURCES_DIR'))      { define('RESOURCES_DIR', INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR); }
if (!defined('LANG_DIR'))           { define('LANG_DIR', RESOURCES_DIR . 'lang' . DIRECTORY_SEPARATOR); }
if (!defined('CONFIG_DIR'))         { define('CONFIG_DIR', RESOURCES_DIR . 'config' . DIRECTORY_SEPARATOR); }
if (!defined('TEMPLATE_DIR'))       { define('TEMPLATE_DIR', RESOURCES_DIR . 'template' . DIRECTORY_SEPARATOR); }
if (!defined('TEMPLATE_CACHE_DIR')) { define('TEMPLATE_CACHE_DIR', TEMPLATE_DIR . 'cache' . DIRECTORY_SEPARATOR); }
if (!defined('SRC_DIR'))            { define('SRC_DIR', INDEX_DIR . 'src' . DIRECTORY_SEPARATOR); }
if (!defined('CLASS_DIR'))          { define('CLASS_DIR', SRC_DIR . 'AstrX' . DIRECTORY_SEPARATOR); }

// PSR-4 autoloader for the AstrX\ namespace (same mapping as bootstrap.php),
// WITHOUT booting the whole application.
spl_autoload_register(static function (string $class): void {
    $prefix = 'AstrX\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) { return; }
    $file = CLASS_DIR . str_replace('\\', '/', substr($class, $len)) . '.php';
    if (is_file($file)) { require_once $file; }
});

// Support helpers — SecureSessionHandler::ikm() calls \AstrX\Support\configDir().
$supportConstants = CLASS_DIR . 'Support/constants.php';
if (is_file($supportConstants)) { require_once $supportConstants; }

// ── Build the PDO exactly as ContentManager::initPDO() does ───────────────────
$pdoCfgPath = CONFIG_DIR . 'PDO.config.php';
if (!is_file($pdoCfgPath)) { $deny(); }
/** @var mixed $pdoCfgRaw */
$pdoCfgRaw = require $pdoCfgPath;
if (!is_array($pdoCfgRaw) || !isset($pdoCfgRaw['PDO']) || !is_array($pdoCfgRaw['PDO'])) { $deny(); }
/** @var array<string,mixed> $db */
$db = $pdoCfgRaw['PDO'];

try {
    $dsn = $asStr($db['db_type'] ?? null, 'mysql')
         . ':host=' . $asStr($db['db_host'] ?? null, 'localhost')
         . ';dbname=' . $asStr($db['db_name'] ?? null)
         . ';charset=utf8mb4';
    $pdo = new PDO(
        $dsn,
        $asStr($db['db_username'] ?? null),
        $asStr($db['db_password'] ?? null),
    );
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, (bool)($db['emulate_prepares'] ?? false));
    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        ($db['errmode_exception'] ?? true) ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT,
    );
    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        ($db['default_fetch_assoc'] ?? true) ? PDO::FETCH_ASSOC : PDO::FETCH_BOTH,
    );
} catch (\Throwable) {
    // DB unreachable → cannot verify admin → fail closed.
    $deny();
}

// ── Install SecureSessionHandler with the same config the framework injects ───
/** @var PDO $pdo */
$handler = new SecureSessionHandler($pdo);
$sessionCfgPath = CONFIG_DIR . 'Session.config.php';
if (is_file($sessionCfgPath)) {
    /** @var mixed $sessionCfgRaw */
    $sessionCfgRaw = require $sessionCfgPath;
    if (is_array($sessionCfgRaw) && isset($sessionCfgRaw['Session']) && is_array($sessionCfgRaw['Session'])) {
        /** @var array<string,mixed> $s */
        $s = $sessionCfgRaw['Session'];
        if (isset($s['sid_bytes']))       { $handler->setSidBytes($asInt($s['sid_bytes'])); }
        if (isset($s['encrypt']))         { $handler->setEncrypt((bool)$s['encrypt']); }
        if (isset($s['max_sid_retries'])) { $handler->setMaxRetries($asInt($s['max_sid_retries'])); }
        if (isset($s['server_secret']))   { $handler->setServerSecret($asStr($s['server_secret'])); }
    }
}

// No session cookie → nobody to authenticate. Avoid creating a stray session row.
if ($asStr($_COOKIE[session_name()] ?? null) === '') { $deny(); }

// Strict mode: reject uninitialised/forged IDs (activates validateId()).
ini_set('session.use_strict_mode', '1');

$isHttps = (($_SERVER['HTTPS'] ?? 'off') !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_set_save_handler($handler, true);

if (@session_start() === false) {
    $deny();
}

$userSession = new UserSession();
$isAdmin = $userSession->isLoggedIn() && $userSession->userType() === UserGroup::ADMIN;

if (!$isAdmin) {
    // Discard any pending write so anonymous hits don't persist session rows.
    session_abort();
    $deny();
}

// Authenticated admin — serve phpinfo(), but never let it be cached.
header('Cache-Control: no-store');
phpinfo();
