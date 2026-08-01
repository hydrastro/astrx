<?php
declare(strict_types=1);

/**
 * AstrX CLI installer — `php tools/install.php`
 *
 * A zero-dependency, command-line counterpart to public/setup.php. It performs
 * the same install operations, but runs OUTSIDE the web docroot so it never
 * exposes an install surface to the network:
 *
 *   1. Write resources/config/PDO.config.php (after testing the connection).
 *   2. (optional) Run the SQL schema + migrations from src/setup/ or setup/.
 *   3. Create the first administrator (Argon2id, same as the wizard).
 *   4. Generate + write a UNIQUE server_secret into Session.config.php.
 *   5. Set the environment in config.php.
 *
 * Values are read from argv (e.g. --db-host=... --admin-user=...) or prompted
 * interactively when a TTY is attached. Run with --help for the full list.
 *
 * Example (fully non-interactive):
 *   php tools/install.php --no-input \
 *       --db-host=mariadb --db-name=content_manager --db-user=user --db-pass=secret \
 *       --admin-user=admin --admin-pass='a-strong-password' --admin-mailbox=admin \
 *       --environment=production
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This installer runs on the command line only.\n");
}

// ─────────────────────────────────────────────────────────────────────────────
// Tiny CLI helpers
// ─────────────────────────────────────────────────────────────────────────────

function out(string $s): void { fwrite(STDOUT, $s); }
function err(string $s): void { fwrite(STDERR, $s); }
function fail(string $msg): never { err("\nERROR: {$msg}\n"); exit(1); }

function isInteractive(): bool
{
    return defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN);
}

function prompt(string $label, ?string $default = null): string
{
    out($label . ($default !== null ? " [{$default}]" : '') . ': ');
    $line = fgets(STDIN);
    if ($line === false) { return $default ?? ''; }
    $line = trim($line);
    return ($line === '' && $default !== null) ? $default : $line;
}

function promptHidden(string $label): string
{
    out($label . ': ');
    $canHide = stripos(PHP_OS, 'WIN') === false && function_exists('shell_exec');
    if ($canHide) { @shell_exec('stty -echo'); }
    $line = fgets(STDIN);
    if ($canHide) { @shell_exec('stty echo'); out("\n"); }
    return $line === false ? '' : trim($line);
}

/**
 * Parse "--key=value", "--key value" and boolean "--flag" argv into a map.
 *
 * @param  list<string>            $argv
 * @return array<string,string|bool>
 */
function parseArgs(array $argv): array
{
    $args = [];
    $n = count($argv);
    for ($i = 1; $i < $n; $i++) {
        $a = $argv[$i];
        if (!str_starts_with($a, '--')) { continue; }
        $a = substr($a, 2);
        if (str_contains($a, '=')) {
            [$k, $v] = explode('=', $a, 2);
            $args[$k] = $v;
            continue;
        }
        $next = $argv[$i + 1] ?? null;
        if ($next !== null && !str_starts_with($next, '--')) {
            $args[$a] = $next;
            $i++;
        } else {
            $args[$a] = true;
        }
    }
    return $args;
}

/** @param array<string,string|bool> $args */
function argVal(array $args, string $key): ?string
{
    return (array_key_exists($key, $args) && is_string($args[$key])) ? $args[$key] : null;
}

/** @param array<string,string|bool> $args */
function hasFlag(array $args, string $key): bool
{
    if (!array_key_exists($key, $args)) { return false; }
    $v = $args[$key];
    return $v === true || $v === '1' || $v === 'true' || $v === '';
}

/**
 * Resolve a value: prefer argv, else prompt (interactive), else default/fail.
 *
 * @param array<string,string|bool> $args
 */
function resolve(
    array $args,
    string $key,
    string $label,
    ?string $default,
    bool $noInput,
    bool $required = true,
    bool $hidden = false,
): string {
    $val = argVal($args, $key);
    if ($val !== null) { return $val; }

    if ($noInput || !isInteractive()) {
        if ($default !== null) { return $default; }
        if ($required)         { fail("Missing required --{$key} (non-interactive mode)."); }
        return '';
    }

    $entered = $hidden ? promptHidden($label) : prompt($label, $default);
    if ($entered === '' && $default !== null) { return $default; }
    return $entered;
}

// ─────────────────────────────────────────────────────────────────────────────
// Install operations (mirrors public/setup.php)
// ─────────────────────────────────────────────────────────────────────────────

function tryConn(string $h, string $d, string $u, string $p, int $port): PDO|string
{
    try {
        return new PDO(
            "mysql:host={$h};port={$port};dbname={$d};charset=utf8mb4",
            $u,
            $p,
            [
                PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT                  => 5,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ],
        );
    } catch (\PDOException $e) {
        return $e->getMessage();
    }
}

/**
 * Write $content to $path atomically (temp file + rename). rename() replaces the
 * target needing only the DIRECTORY writable — so a config file shipped read-only
 * (0644 owned by a different user than whoever runs the installer) doesn't block
 * the write. Mirrors the web installer (public/setup.php).
 */
function atomicWrite(string $path, string $content): bool
{
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function writePDO(string $configDir, string $h, string $d, string $u, string $p, int $port): string
{
    $path = $configDir . 'PDO.config.php';
    // Keep the existing addslashes on config writes so values are safely embedded
    // in single-quoted PHP string literals.
    [$h2, $d2, $u2, $p2] = array_map('addslashes', [$h, $d, $u, $p]);
    $content = "<?php\ndeclare(strict_types=1);\nreturn [\n    'PDO' => [\n        'db_type'             => 'mysql',\n        'db_host'             => '{$h2}',\n        'db_name'             => '{$d2}',\n        'db_port'             => {$port},\n        'db_username'         => '{$u2}',\n        'db_password'         => '{$p2}',\n        'emulate_prepares'    => false,\n        'errmode_exception'   => true,\n        'default_fetch_assoc' => true,\n    ],\n];\n";
    return atomicWrite($path, $content)
        ? ''
        : "Cannot write {$path}. Ensure the resources/config/ directory is writable by whoever runs the installer.";
}

function writeServerSecret(string $configDir, string $secret): string
{
    $path = $configDir . 'Session.config.php';
    $content = @file_get_contents($path);
    if ($content === false) { return "Cannot read {$path}."; }
    // preg_replace_callback so the secret is never interpreted as a replacement
    // backreference; addslashes keeps the written PHP literal valid.
    $new = preg_replace_callback(
        "/'server_secret'\s*=>\s*'[^']*'/",
        static fn(): string => "'server_secret' => '" . addslashes($secret) . "'",
        $content,
        1,
        $count,
    );
    if ($new === null || $count === 0) {
        return "Could not locate server_secret in {$path}.";
    }
    return atomicWrite($path, $new) ? '' : "Cannot write {$path}.";
}

function writeEnvironment(string $configDir, int $envInt): string
{
    $path = $configDir . 'config.php';
    $content = @file_get_contents($path);
    if ($content === false) { return "Cannot read {$path}."; }

    // config.php stores a plain int (e.g. 'environment' => 1).
    $new = preg_replace_callback(
        "/('environment'\s*=>\s*)\d+/",
        static fn(array $m): string => $m[1] . $envInt,
        $content,
        1,
        $count,
    );
    if ($new === null || $count === 0) {
        // Fall back to the EnvironmentType::X->value form, if that is used instead.
        $map = [0 => 'DEVELOPMENT', 1 => 'PRODUCTION', 2 => 'TESTING', 3 => 'STAGING'];
        $const = $map[$envInt] ?? 'PRODUCTION';
        $new = preg_replace_callback(
            "/('environment'\s*=>\s*)EnvironmentType::[A-Z]+->value/",
            static fn(array $m): string => $m[1] . 'EnvironmentType::' . $const . '->value',
            $content,
            1,
            $count,
        );
        if ($new === null || $count === 0) {
            return "Could not locate environment in {$path}.";
        }
    }
    return atomicWrite($path, $new) ? '' : "Cannot write {$path}.";
}

/**
 * Split a SQL script into statements, respecting single/double-quoted strings,
 * backtick identifiers, and --/#/block comments — so a ';', '--' or '#' inside
 * a string literal cannot truncate or corrupt a statement (the naive
 * explode(';') approach could mangle seed data).
 *
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
{
    $stmts = [];
    $buf   = '';
    $len   = strlen($sql);
    $inS = false; $inD = false; $inB = false; // single, double, backtick
    for ($i = 0; $i < $len; $i++) {
        $c    = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inS) { $buf .= $c; if ($c === '\\' && $next !== '') { $buf .= $next; $i++; } elseif ($c === "'") { $inS = false; } continue; }
        if ($inD) { $buf .= $c; if ($c === '\\' && $next !== '') { $buf .= $next; $i++; } elseif ($c === '"') { $inD = false; } continue; }
        if ($inB) { $buf .= $c; if ($c === '`') { $inB = false; } continue; }

        // Line comment: '-- ' (dash-dash + whitespace/EOL, per SQL) or '#'
        if ($c === '-' && $next === '-') {
            $after = $i + 2 < $len ? $sql[$i + 2] : "\n";
            if ($after === ' ' || $after === "\t" || $after === "\n" || $after === "\r") {
                $nl = strpos($sql, "\n", $i);
                if ($nl === false) { break; }
                $i = $nl; $buf .= "\n"; continue;
            }
        }
        if ($c === '#') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) { break; }
            $i = $nl; $buf .= "\n"; continue;
        }
        // Block comment /* ... */
        if ($c === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) { break; }
            $i = $end + 1; continue;
        }

        if ($c === "'") { $inS = true; $buf .= $c; continue; }
        if ($c === '"') { $inD = true; $buf .= $c; continue; }
        if ($c === '`') { $inB = true; $buf .= $c; continue; }
        if ($c === ';') { $t = trim($buf); if ($t !== '') { $stmts[] = $t; } $buf = ''; continue; }

        $buf .= $c;
    }
    $t = trim($buf);
    if ($t !== '') { $stmts[] = $t; }
    return $stmts;
}

function createDatabase(string $h, string $u, string $p, int $port, string $dbName): string
{
    try {
        $pdo = new PDO(
            "mysql:host={$h};port={$port};charset=utf8mb4",
            $u,
            $p,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
        $safe = '`' . str_replace('`', '``', $dbName) . '`';
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$safe} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        return '';
    } catch (\PDOException $e) {
        return $e->getMessage();
    }
}

function runSQL(PDO $pdo, string $file): string
{
    if (!is_file($file)) { return "Schema file not found: {$file}"; }
    $sql = (string) file_get_contents($file);
    $stmts = splitSqlStatements($sql);

    foreach ($stmts as $stmt) {
        try {
            $cursor = $pdo->query($stmt);
            if ($cursor !== false) {
                if ($cursor->columnCount() > 0) { $cursor->fetchAll(PDO::FETCH_ASSOC); }
                $cursor->closeCursor();
            }
        } catch (\PDOException $e) {
            // Ignore ONLY specific "already exists" / duplicate-key SQLSTATEs so
            // re-runs are safe. NOT '42000' (generic syntax/access-rule violation):
            // swallowing it recorded genuinely-failed migrations as applied, and
            // the checksum lock then blocked any clean re-run.
            if (!in_array((string) $e->getCode(), ['42S01', '42S21', '23000'], true)) {
                return $e->getMessage() . ' | ' . substr($stmt, 0, 200);
            }
        }
    }
    return '';
}

function ensureMigrationTable(PDO $pdo): string
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `migration` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `file_name` VARCHAR(255) NOT NULL,
                `checksum` CHAR(64) NOT NULL,
                `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `migration_file_name_uq` (`file_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
        return '';
    } catch (\PDOException $e) {
        return $e->getMessage();
    }
}

function runMigration(PDO $pdo, string $file): string
{
    $err = ensureMigrationTable($pdo);
    if ($err !== '') { return 'Could not initialise migration table: ' . $err; }
    if (!is_file($file)) { return "Migration file not found: {$file}"; }

    $name = basename($file);
    $checksum = hash_file('sha256', $file);
    if (!is_string($checksum)) { return "Could not checksum: {$file}"; }

    try {
        $check = $pdo->prepare('SELECT checksum FROM `migration` WHERE file_name = :f LIMIT 1');
        $check->execute([':f' => $name]);
        $existing = $check->fetchColumn();
        $check->closeCursor();
        if (is_string($existing) && $existing !== '') {
            return hash_equals($existing, $checksum)
                ? ''
                : "Migration {$name} already ran with a different checksum.";
        }
    } catch (\PDOException $e) {
        return $e->getMessage();
    }

    $err = runSQL($pdo, $file);
    if ($err !== '') { return $err; }

    try {
        $rec = $pdo->prepare('INSERT INTO `migration` (file_name, checksum) VALUES (:f, :c)');
        $rec->execute([':f' => $name, ':c' => $checksum]);
        return '';
    } catch (\PDOException $e) {
        return $e->getMessage();
    }
}

function findSetupFile(string $root, string $name): ?string
{
    foreach ([$root . '/src/setup/' . $name, $root . '/setup/' . $name] as $c) {
        if (is_file($c)) { return $c; }
    }
    return null;
}

/** @return list<string> */
function listSetupMigrations(string $root): array
{
    $found = [];
    foreach ([$root . '/src/setup/', $root . '/setup/'] as $dir) {
        if (!is_dir($dir)) { continue; }
        foreach (glob($dir . 'migrate_*.sql') ?: [] as $m) {
            $found[basename($m)] = $m; // de-dup by filename
        }
    }
    return array_values($found);
}

function removeSeedAdmin(PDO $pdo): string
{
    $legacyHash = '$argon2id$v=19$m=65536,t=4,p=1$b2Z2cnVLM0pSMy9xUVVicw$6KUaczD3Y6rGl28q61y6YXxriNmGqKv2I6xucl8rcSE';
    try {
        $stmt = $pdo->prepare('DELETE FROM `user` WHERE username = :u AND password = :p AND type = 1 AND verified = 1 AND deleted = 0');
        $stmt->execute([':u' => 'Administrator', ':p' => $legacyHash]);
        return '';
    } catch (\PDOException $e) {
        return $e->getMessage();
    }
}

function makeAdmin(PDO $pdo, string $user, string $pass, string $mbox): string
{
    try {
        // R4-20: idempotent. A bare INSERT into the UNIQUE username threw 23000
        // on any re-run of the installer. ON DUPLICATE KEY UPDATE re-provisions
        // the existing admin (mailbox/password/flags) in place, keeping its id.
        $stmt = $pdo->prepare(
            'INSERT INTO `user` (id,username,mailbox,password,type,verified,deleted)
             VALUES (UNHEX(:id),:u,:m,:p,1,1,0)
             ON DUPLICATE KEY UPDATE
                 `mailbox`  = VALUES(`mailbox`),
                 `password` = VALUES(`password`),
                 `type`     = VALUES(`type`),
                 `verified` = VALUES(`verified`),
                 `deleted`  = VALUES(`deleted`)'
        );
        $stmt->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':u'  => $user,
            ':m'  => $mbox,
            ':p'  => password_hash($pass, PASSWORD_ARGON2ID),
        ]);
        return '';
    } catch (\PDOException $e) {
        return $e->getMessage();
    }
}

function printUsage(): void
{
    out(<<<TXT
    AstrX CLI installer

    Usage:
      php tools/install.php [options]

    Database:
      --db-host=HOST         Database host (default: localhost)
      --db-name=NAME         Database name (default: content_manager)
      --db-port=PORT         Database port (default: 3306)
      --db-user=USER         Database username (default: user — the least-privilege account from init.sql)
      --db-pass=PASS         Database password (prompted hidden if omitted)

    Admin account:
      --admin-user=NAME      First administrator username (default: admin)
      --admin-pass=PASS      Admin password, min 8 chars (prompted hidden if omitted)
      --admin-mailbox=BOX    IMAP local-part (default: same as admin username)

    Security / environment:
      --server-secret=HEX    Session server secret (default: generated)
      --environment=ENV      production | staging | testing | development (default: production)

    Behaviour:
      --create-db            CREATE DATABASE (utf8mb4) if it doesn't exist yet
      --skip-schema          Do not run tables.sql / migrations (assume DB is ready)
      --no-input             Never prompt; require values via flags or use defaults
      --help, -h             Show this help

    TXT);
}

// ─────────────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────────────

/** @var list<string> $argv */
$argv = $argv ?? [];
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    printUsage();
    exit(0);
}

$args    = parseArgs($argv);
$noInput = hasFlag($args, 'no-input');
$root    = dirname(__DIR__);
$configDir = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR;

if (!is_dir($configDir)) {
    fail("Config directory not found: {$configDir}");
}

if (!extension_loaded('pdo_mysql')) {
    fail('The pdo_mysql extension is required but not loaded.');
}

// Fail fast if we won't be able to write the config files we must update later.
foreach (['PDO.config.php', 'Session.config.php', 'config.php'] as $cf) {
    $p = $configDir . $cf;
    if ((is_file($p) && !is_writable($p)) || (!is_file($p) && !is_writable($configDir))) {
        fail("Config not writable: {$p} — the user running the installer must own resources/config/.");
    }
}

out("AstrX CLI installer\n===================\n\n");

// ── 1. Database connection ──────────────────────────────────────────────────
$dbHost = resolve($args, 'db-host', 'Database host', 'localhost',        $noInput);
$dbName = resolve($args, 'db-name', 'Database name', 'content_manager',  $noInput);
$dbPort = (int) resolve($args, 'db-port', 'Database port', '3306',       $noInput);
$dbUser = resolve($args, 'db-user', 'Database username', 'user',         $noInput);
$dbPass = resolve($args, 'db-pass', 'Database password', null,           $noInput, required: false, hidden: true);

if (hasFlag($args, 'create-db')) {
    out("Creating database '{$dbName}' if absent... ");
    $err = createDatabase($dbHost, $dbUser, $dbPass, $dbPort, $dbName);
    if ($err !== '') { fail("could not create database: {$err}"); }
    out("ok\n");
}

out("\nTesting database connection... ");
$conn = tryConn($dbHost, $dbName, $dbUser, $dbPass, $dbPort);
if (is_string($conn)) {
    fail("database connection failed: {$conn}");
}
out("ok\n");

$err = writePDO($configDir, $dbHost, $dbName, $dbUser, $dbPass, $dbPort);
if ($err !== '') { fail($err); }
out("Wrote PDO.config.php\n");

// ── 2. Schema + migrations (optional) ───────────────────────────────────────
if (!hasFlag($args, 'skip-schema')) {
    $tables = findSetupFile($root, 'tables.sql');
    if ($tables === null) {
        fail('Schema file tables.sql not found in src/setup/ or setup/. Use --skip-schema if the database is already provisioned.');
    }
    out("Applying schema ({$tables})... ");
    $err = runSQL($conn, $tables);
    if ($err !== '') { fail("SQL error: {$err}"); }
    out("ok\n");

    $err = ensureMigrationTable($conn);
    if ($err !== '') { fail("Could not initialise migration table: {$err}"); }

    foreach (listSetupMigrations($root) as $mf) {
        out('Migration ' . basename($mf) . '... ');
        $err = runMigration($conn, $mf);
        if ($err !== '') { fail('SQL error in ' . basename($mf) . ": {$err}"); }
        out("ok\n");
    }
} else {
    out("Skipping schema (--skip-schema)\n");
}

// ── 3. First administrator ──────────────────────────────────────────────────
$adminUser = resolve($args, 'admin-user', 'Admin username', 'admin', $noInput);
if ($adminUser === '') { fail('Admin username is required.'); }

$adminPass = argVal($args, 'admin-pass');
if ($adminPass === null) {
    if ($noInput || !isInteractive()) {
        fail('Missing required --admin-pass (non-interactive mode).');
    }
    // Interactive: loop until a valid, confirmed password is entered.
    while (true) {
        $p1 = promptHidden('Admin password (min 8 chars)');
        if (strlen($p1) < 8) { out("  Password must be at least 8 characters.\n"); continue; }
        $p2 = promptHidden('Repeat password');
        if ($p1 !== $p2)     { out("  Passwords do not match.\n"); continue; }
        $adminPass = $p1;
        break;
    }
}
if (strlen($adminPass) < 8) { fail('Admin password must be at least 8 characters.'); }

$adminMailbox = resolve($args, 'admin-mailbox', 'Admin mailbox (IMAP local-part)', $adminUser, $noInput, required: false);
if ($adminMailbox === '') { $adminMailbox = $adminUser; }

$err = removeSeedAdmin($conn);
if ($err !== '') { fail("Could not remove legacy seeded admin: {$err}"); }
$err = makeAdmin($conn, $adminUser, $adminPass, $adminMailbox);
if ($err !== '') { fail("Could not create admin: {$err}"); }
out("Created administrator '{$adminUser}'\n");

// ── 4. Server secret ────────────────────────────────────────────────────────
$serverSecret = argVal($args, 'server-secret');
if ($serverSecret === null || $serverSecret === '') {
    $serverSecret = bin2hex(random_bytes(32));
    out("Generated a unique server_secret\n");
}
$err = writeServerSecret($configDir, $serverSecret);
if ($err !== '') { fail($err); }
out("Wrote server_secret to Session.config.php\n");

// ── 5. Environment ──────────────────────────────────────────────────────────
$envRaw = strtolower(resolve($args, 'environment', 'Environment (production/staging/testing/development)', 'production', $noInput));
$envInt = match ($envRaw) {
    'development', 'dev' => 0,
    'testing', 'test'    => 2,
    'staging'            => 3,
    default              => 1, // production
};
$err = writeEnvironment($configDir, $envInt);
if ($err !== '') { fail($err); }
out("Set environment to '{$envRaw}'\n");

// ── Finalise ────────────────────────────────────────────────────────────────
// Lock the web installer and drop any one-time setup token it may have created.
@file_put_contents($configDir . '.setup_complete', date('c'));
@unlink($configDir . '.setup_token');

out("\nInstall complete. Remember to remove public/setup.php from the docroot.\n");
exit(0);
