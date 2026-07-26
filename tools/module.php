<?php
declare(strict_types=1);

/**
 * AstrX module manager — `php tools/module.php <command> [module]`
 *
 * The command-line switchboard for AstrX's optional modules. It reads and writes
 * resources/config/Modules.config.php (the same file ModuleRegistry reads at
 * runtime) and, for teardown, runs a module's src/setup/modules/<module>.down.sql.
 *
 *   status                 List every manageable module: enabled? installed pages?
 *   enable  <module>       Turn a module on  (its nav + pages reappear).
 *   disable <module>       Turn a module off (nav entries drop, pages 404). Reversible.
 *   purge   <module>       disable + DROP the module's tables and DELETE its pages.
 *                          DESTRUCTIVE and one-way — reinstall the schema to restore.
 *   help                   This message.
 *
 * enable/disable only edit the config file (no DB needed). status shows page
 * counts and purge run against the DB via resources/config/PDO.config.php.
 * Runs OUTSIDE the web docroot — never exposes a module surface to the network.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This tool runs on the command line only.\n");
}

function m_out(string $s): void { fwrite(STDOUT, $s); }
function m_err(string $s): void { fwrite(STDERR, $s); }
function m_fail(string $msg): never { m_err("\nERROR: {$msg}\n"); exit(1); }

$root       = dirname(__DIR__);
$configDir  = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;
$modulesCfg = $configDir . 'Modules.config.php';
$modulesDir = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR;

/**
 * Current enabled/disabled map from Modules.config.php.
 *
 * @return array<string,bool>
 */
function m_read_modules(string $file): array
{
    if (!is_file($file)) { return []; }
    /** @var mixed $data */
    $data = require $file;
    if (!is_array($data) || !isset($data['Modules']) || !is_array($data['Modules'])) { return []; }
    $out = [];
    /** @var mixed $v */
    foreach ($data['Modules'] as $k => $v) {
        if (is_string($k)) { $out[$k] = (bool) $v; }
    }
    return $out;
}

/**
 * Every manageable module = manifest keys ∪ config keys ∪ modules that ship a
 * teardown file. Manifests (src/AstrX/<Module>/module.php) are the primary
 * source of truth; the other two keep the list complete for partially-wired ones.
 *
 * @param  array<string,bool> $configured
 * @return list<string>
 */
function m_known_modules(array $configured, string $modulesDir, string $classDir): array
{
    $keys = array_keys($configured);
    foreach (glob($modulesDir . '*.down.sql') ?: [] as $f) {
        $keys[] = basename($f, '.down.sql');
    }
    foreach (glob($classDir . '*' . DIRECTORY_SEPARATOR . 'module.php') ?: [] as $f) {
        /** @var mixed $m */
        $m = require $f;
        if (is_array($m) && isset($m['key']) && is_string($m['key']) && $m['key'] !== '') {
            $keys[] = $m['key'];
        }
    }
    $keys = array_values(array_unique($keys));
    sort($keys);
    return $keys;
}

/** Rewrite Modules.config.php so $mod => $enabled, preserving the file's comments. */
function m_set_flag(string $file, string $mod, bool $enabled): string
{
    $content = @file_get_contents($file);
    if ($content === false) { return "Cannot read {$file}."; }
    $val    = $enabled ? 'true' : 'false';
    $quoted = preg_quote($mod, '/');

    if (preg_match("/'{$quoted}'\s*=>\s*(?:true|false)/", $content) === 1) {
        $new = preg_replace("/('{$quoted}'\s*=>\s*)(?:true|false)/", '${1}' . $val, $content, 1);
    } else {
        // Insert a new entry right after the "'Modules' => [" opening line.
        $new = preg_replace(
            "/('Modules'\s*=>\s*\[\s*\n)/",
            "\${1}        '{$mod}' => {$val},\n",
            $content,
            1,
        );
    }
    if (!is_string($new)) { return "Could not update {$file}."; }
    return @file_put_contents($file, $new) === false ? "Cannot write {$file}." : '';
}

/** Build a PDO from PDO.config.php, or null if unavailable/unreadable. */
function m_build_pdo(string $configDir): ?PDO
{
    $file = $configDir . 'PDO.config.php';
    if (!is_file($file)) { return null; }
    /** @var mixed $cfg */
    $cfg = require $file;
    if (!is_array($cfg) || !isset($cfg['PDO']) || !is_array($cfg['PDO'])) { return null; }
    $p = $cfg['PDO'];

    $s = static fn(string $k, string $d): string => isset($p[$k]) && is_scalar($p[$k]) ? (string) $p[$k] : $d;
    $host = $s('db_host', 'localhost');
    $name = $s('db_name', 'content_manager');
    $user = $s('db_username', 'root');
    $pass = $s('db_password', '');
    $port = isset($p['db_port']) && is_numeric($p['db_port']) ? (int) $p['db_port'] : 3306;

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch (PDOException) {
        return null;
    }
}

/**
 * Page counts per module, or an empty map if the DB/column is unavailable.
 *
 * @return array<string,int>
 */
function m_page_counts(PDO $pdo): array
{
    try {
        $stmt = $pdo->query("SELECT `module`, COUNT(*) AS `c` FROM `page` WHERE `module` <> '' GROUP BY `module`");
        if ($stmt === false) { return []; }
        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mod = isset($row['module']) && is_scalar($row['module']) ? (string) $row['module'] : '';
            $cnt = isset($row['c']) && is_numeric($row['c']) ? (int) $row['c'] : 0;
            if ($mod !== '') { $out[$mod] = $cnt; }
        }
        return $out;
    } catch (PDOException) {
        return [];
    }
}

/** Run a .down.sql teardown file statement-by-statement, tolerating "already gone" errors. */
function m_run_sql_file(PDO $pdo, string $file): string
{
    $sql = @file_get_contents($file);
    if ($sql === false) { return "Cannot read {$file}."; }

    // Strip line comments, then split on ';' (teardown files carry no ';' inside
    // string literals, so a naive split is safe here).
    $noComments = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = is_string($noComments) ? $noComments : $sql;

    foreach (explode(';', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') { continue; }
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // 42S02 = unknown table, 42S01 = table exists — safe to ignore on teardown.
            if (!in_array((string) $e->getCode(), ['42S02', '42S01'], true)) {
                return $e->getMessage() . ' | ' . substr($stmt, 0, 160);
            }
        }
    }
    return '';
}

// ─────────────────────────────────────────────────────────────────────────────
// Commands
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array<string,bool> $configured
 * @param list<string>       $known
 */
function m_cmd_status(array $configured, array $known, string $configDir): void
{
    $pdo    = m_build_pdo($configDir);
    $counts = $pdo instanceof PDO ? m_page_counts($pdo) : [];

    m_out("AstrX modules\n=============\n\n");
    m_out(sprintf("  %-14s %-9s %s\n", 'MODULE', 'STATE', $pdo instanceof PDO ? 'PAGES' : ''));
    foreach ($known as $mod) {
        $on    = $configured[$mod] ?? true; // default ON, matching ModuleRegistry
        $state = $on ? 'enabled' : 'disabled';
        $pages = $pdo instanceof PDO ? (string) ($counts[$mod] ?? 0) : '';
        m_out(sprintf("  %-14s %-9s %s\n", $mod, $state, $pages));
    }
    if (!$pdo instanceof PDO) {
        m_out("\n(no DB connection — page counts hidden; configure resources/config/PDO.config.php)\n");
    }
    m_out("\nEdit resources/config/Modules.config.php, or: module.php enable|disable|purge <module>\n");
}

/**
 * @param list<string> $known
 */
function m_cmd_toggle(string $modulesCfg, array $known, string $mod, bool $enabled): void
{
    if ($mod === '') { m_fail('which module? usage: module.php ' . ($enabled ? 'enable' : 'disable') . ' <module>'); }
    if (!in_array($mod, $known, true)) {
        m_fail("unknown module '{$mod}'. Known: " . implode(', ', $known));
    }
    $err = m_set_flag($modulesCfg, $mod, $enabled);
    if ($err !== '') { m_fail($err); }
    m_out("Module '{$mod}' " . ($enabled ? 'ENABLED' : 'DISABLED') . " (resources/config/Modules.config.php).\n");
    if (!$enabled) {
        m_out("Its nav entries now drop and its pages 404. Data is untouched — `enable` restores it, `purge` removes it.\n");
    }
}

/**
 * @param list<string> $known
 */
function m_cmd_purge(string $modulesCfg, string $modulesDir, array $known, string $mod, string $configDir): void
{
    if ($mod === '') { m_fail('which module? usage: module.php purge <module>'); }
    if (!in_array($mod, $known, true)) {
        m_fail("unknown module '{$mod}'. Known: " . implode(', ', $known));
    }
    $downFile = $modulesDir . $mod . '.down.sql';
    if (!is_file($downFile)) { m_fail("no teardown file for '{$mod}' ({$downFile})."); }

    $pdo = m_build_pdo($configDir);
    if (!$pdo instanceof PDO) { m_fail('purge needs a database connection — check resources/config/PDO.config.php.'); }

    m_out("Purging module '{$mod}' — dropping its tables and deleting its pages...\n");
    $err = m_run_sql_file($pdo, $downFile);
    if ($err !== '') { m_fail("teardown failed: {$err}"); }

    $err = m_set_flag($modulesCfg, $mod, false);
    if ($err !== '') { m_fail($err); }
    m_out("Purged '{$mod}' and disabled it. Reinstall the schema (tools/install.php) to restore it.\n");
}

function m_usage(): void
{
    m_out(<<<TXT
    AstrX module manager

    Usage:
      php tools/module.php <command> [module]

      status               List modules: enabled/disabled + installed page counts.
      enable  <module>     Turn a module on.
      disable <module>     Turn a module off (nav drops, pages 404). Reversible.
      purge   <module>     Disable AND drop the module's tables + delete its pages.
                           DESTRUCTIVE, one-way (reinstall the schema to restore).
      help                 Show this message.

    Modules are also toggled by editing resources/config/Modules.config.php.

    TXT);
}

// ─────────────────────────────────────────────────────────────────────────────
// Dispatch
// ─────────────────────────────────────────────────────────────────────────────

/** @var list<string> $argv */
$argv = $argv ?? [];
$cmd  = $argv[1] ?? 'status';
$mod  = $argv[2] ?? '';

$classDir   = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'AstrX' . DIRECTORY_SEPARATOR;
$configured = m_read_modules($modulesCfg);
$known      = m_known_modules($configured, $modulesDir, $classDir);

switch ($cmd) {
    case 'status':
    case 'list':
        m_cmd_status($configured, $known, $configDir);
        break;
    case 'enable':
        m_cmd_toggle($modulesCfg, $known, $mod, true);
        break;
    case 'disable':
        m_cmd_toggle($modulesCfg, $known, $mod, false);
        break;
    case 'purge':
        m_cmd_purge($modulesCfg, $modulesDir, $known, $mod, $configDir);
        break;
    case 'help':
    case '-h':
    case '--help':
        m_usage();
        break;
    default:
        m_fail("unknown command '{$cmd}' (try: status, enable, disable, purge, help)");
}

exit(0);
