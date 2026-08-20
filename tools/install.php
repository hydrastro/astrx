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
                // Migrations do conditional DDL with PREPARE / EXECUTE /
                // DEALLOCATE PREPARE — the only form of "add this index only if
                // it is missing" that both MySQL 8 and MariaDB accept. Those
                // three statements are rejected by MySQL's native
                // prepared-statement protocol (ER_UNSUPPORTED_PS, 1295), which
                // is what PDO::query() uses once emulation is off. This is the
                // installer's connection only; the application's runtime PDO
                // (PDO.config.php) keeps emulate_prepares = false.
                PDO::ATTR_EMULATE_PREPARES         => true,
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
        $portable = portableDdl($pdo, $stmt);
        if ($portable === null) {
            continue; // every clause already satisfied — nothing to do
        }
        try {
            $cursor = $pdo->query($portable);
            if ($cursor !== false) {
                if ($cursor->columnCount() > 0) { $cursor->fetchAll(PDO::FETCH_ASSOC); }
                $cursor->closeCursor();
            }
        } catch (\PDOException $e) {
            if (!sqlErrorIsBenign($e)) {
                return $e->getMessage() . ' | ' . substr($portable, 0, 200);
            }
        }
    }
    return '';
}

// ─────────────────────────────────────────────────────────────────────────────
// MariaDB-only DDL → portable DDL
//
// The schema uses `ALTER TABLE … ADD COLUMN IF NOT EXISTS` (33 clauses) to make
// the migrations idempotent. That syntax is a MariaDB extension. MySQL 8 rejects
// it with ER_PARSE_ERROR (SQLSTATE 42000), which runSQL deliberately does NOT
// swallow — so on the MySQL the README says AstrX supports, the install aborted
// on the first such statement.
//
// Rather than rewrite 33 clauses across nine .sql files — which would change
// every migration's sha256 and make runMigration() refuse to proceed on EXISTING
// installs with "already ran with a different checksum" — the portable form is
// produced here, at execution time, and only for servers that need it:
// information_schema is consulted for each clause, satisfied clauses are
// dropped, and what remains is emitted as plain ALTER TABLE. The .sql files stay
// byte-identical, so no installed checksum moves.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Return $stmt in a form this server understands, or null when there is nothing
 * left to execute.
 */
function portableDdl(PDO $pdo, string $stmt): ?string
{
    if (stripos($stmt, 'IF NOT EXISTS') === false || !preg_match('/^\s*ALTER\s+/i', $stmt)) {
        return $stmt;
    }
    if (serverHasIfNotExistsDdl($pdo)) {
        return $stmt;
    }

    if (preg_match('/^\s*ALTER\s+(?:ONLINE\s+|IGNORE\s+)*TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/is', $stmt, $m) !== 1) {
        return $stmt; // not a shape we rewrite; let the server judge it
    }

    $table  = $m[1];
    $kept   = [];
    $seenIfNotExists = false;

    foreach (splitTopLevel($m[2]) as $clause) {
        $clause = trim($clause);
        if ($clause === '') {
            continue;
        }

        if (preg_match(
            '/^ADD\s+(?:COLUMN\s+)?IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s*(.*)$/is',
            $clause,
            $c,
        ) === 1) {
            $seenIfNotExists = true;
            if (schemaObjectExists($pdo, 'COLUMNS', 'COLUMN_NAME', $table, $c[1])) {
                continue;
            }
            $kept[] = 'ADD COLUMN `' . $c[1] . '` ' . trim($c[2]);
            continue;
        }

        if (preg_match(
            '/^ADD\s+(UNIQUE\s+)?(?:INDEX|KEY)\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s*(.*)$/is',
            $clause,
            $i,
        ) === 1) {
            $seenIfNotExists = true;
            if (schemaObjectExists($pdo, 'STATISTICS', 'INDEX_NAME', $table, $i[2])) {
                continue;
            }
            $kept[] = 'ADD ' . (trim($i[1]) !== '' ? 'UNIQUE ' : '') . 'INDEX `' . $i[2] . '` ' . trim($i[3]);
            continue;
        }

        $kept[] = $clause;
    }

    if (!$seenIfNotExists) {
        return $stmt; // IF NOT EXISTS was inside a string literal, not a clause
    }
    if ($kept === []) {
        return null;
    }

    return 'ALTER TABLE `' . $table . '` ' . implode(",\n    ", $kept);
}

/** True when the server accepts MariaDB's IF NOT EXISTS on ALTER TABLE clauses. */
function serverHasIfNotExistsDdl(PDO $pdo): bool
{
    /** @var array<int,bool> $cache spl_object_id => supported */
    static $cache = [];

    $id = spl_object_id($pdo);
    if (isset($cache[$id])) {
        return $cache[$id];
    }

    $version = '';
    try {
        $v = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        if (is_string($v)) { $version = $v; }
    } catch (\PDOException) {
        // Driver refused to report a version; assume the stricter dialect so we
        // take the portable path rather than emitting syntax MySQL rejects.
    }

    // MariaDB has supported it since 10.0 and stamps its name into the version
    // string; every MySQL build does not.
    $supported   = stripos($version, 'mariadb') !== false;
    $cache[$id]  = $supported;

    return $supported;
}

/** Does $table already have a COLUMNS.COLUMN_NAME / STATISTICS.INDEX_NAME named $name? */
function schemaObjectExists(PDO $pdo, string $infoTable, string $nameColumn, string $table, string $name): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM `information_schema`.`{$infoTable}`
              WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = :t AND `{$nameColumn}` = :n
              LIMIT 1"
        );
        $stmt->execute([':t' => $table, ':n' => $name]);
        $found = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $found !== false;
    } catch (\PDOException) {
        // Cannot tell — emit the clause and let the server decide. A genuine
        // duplicate then surfaces as 42S21/1061, which runSQL treats as benign.
        return false;
    }
}

/**
 * Split an ALTER TABLE clause list on its TOP-LEVEL commas.
 *
 * Commas inside `DECIMAL(10,2)`, inside an index's column list, and inside
 * string literals must not split — doing so would cut a clause in half and turn
 * a working ALTER into a syntax error.
 *
 * @return list<string>
 */
function splitTopLevel(string $s): array
{
    $out   = [];
    $buf   = '';
    $depth = 0;
    $quote = '';
    $len   = strlen($s);

    for ($i = 0; $i < $len; $i++) {
        $c = $s[$i];

        if ($quote !== '') {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) { $buf .= $s[++$i]; continue; }
            if ($c === $quote) { $quote = ''; }
            continue;
        }

        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $buf .= $c; continue; }
        if ($c === '(') { $depth++; $buf .= $c; continue; }
        if ($c === ')') { $depth--; $buf .= $c; continue; }
        if ($c === ',' && $depth === 0) { $out[] = $buf; $buf = ''; continue; }

        $buf .= $c;
    }

    if (trim($buf) !== '') { $out[] = $buf; }
    return $out;
}

/**
 * Is this error the harmless "it is already there" that makes a re-run safe?
 *
 * SQLSTATE alone is too coarse. '23000' is "integrity constraint violation" and
 * covers BOTH the duplicate key we want to ignore (1062) AND failures that mean
 * the statement did not do its job:
 *
 *   1451 / 1452  foreign key constraint fails
 *   1048         column cannot be null
 *
 * migrate_zz_content_module.sql ends with
 *     INSERT IGNORE INTO `navbar_internal` (id, page_id)
 *     SELECT @content_nav_id, @content_page_id …
 * and navbar_internal has foreign keys to navbar_entry(id) and page(id). When
 * @content_nav_id picks up a stale LAST_INSERT_ID() — the preceding
 * `INSERT … SELECT NULL WHERE <false>` inserts no row, so LAST_INSERT_ID() still
 * holds whatever ran before it — that INSERT fails with 1452. Swallowed as
 * '23000', the migration was then recorded as applied and the installer printed
 * "ok". The Content nav entry was permanently missing, and the checksum lock
 * guaranteed the migration could never run again to create it.
 *
 * So: keep '42S01' (table/view exists) and '42S21' (duplicate column) whole, and
 * inside '23000' allow only the duplicate-key family.
 */
function sqlErrorIsBenign(\PDOException $e): bool
{
    $sqlState = (string) $e->getCode();

    if ($sqlState === '42S01' || $sqlState === '42S21') {
        return true; // table/view already exists; column already exists
    }

    if ($sqlState !== '23000') {
        return false;
    }

    // Duplicate-key family only:
    //   1022 duplicate key, 1062 duplicate entry,
    //   1169 duplicate entry for a unique index,
    //   1586 duplicate entry, key named.
    $info   = $e->errorInfo;
    $driver = (is_array($info) && isset($info[1]) && is_numeric($info[1])) ? (int) $info[1] : 0;

    return in_array($driver, [1022, 1062, 1169, 1586], true);
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

/**
 * Current connection details from PDO.config.php, as strings, or [] when the
 * file is absent or unreadable. Used only to pre-fill --schema-only.
 *
 * @return array<string,string>
 */
function readPDOConfig(string $configDir): array
{
    $file = $configDir . 'PDO.config.php';
    if (!is_file($file)) { return []; }
    /** @var mixed $cfg */
    $cfg = @include $file;
    if (!is_array($cfg) || !isset($cfg['PDO']) || !is_array($cfg['PDO'])) { return []; }

    $out = [];
    /** @var mixed $v */
    foreach ($cfg['PDO'] as $k => $v) {
        if (is_string($k) && is_scalar($v)) { $out[$k] = (string) $v; }
    }
    return $out;
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
      --schema-only          ONLY (re)apply tables.sql + migrations, then stop.
                             Writes no config file and creates no admin, and
                             takes its connection details from PDO.config.php.
                             This is what restores a module torn down with
                             `php tools/module.php purge <module>`.
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

// --schema-only re-applies the schema and stops: no config write, no admin, no
// server secret, no environment change. It exists so the instruction printed by
// `tools/module.php purge` is a command an operator can actually run — the full
// installer would also prompt for and recreate the administrator.
$schemaOnly = hasFlag($args, 'schema-only');
if ($schemaOnly && hasFlag($args, 'skip-schema')) {
    fail('--schema-only and --skip-schema are opposites; pass at most one.');
}

if (!is_dir($configDir)) {
    fail("Config directory not found: {$configDir}");
}

if (!extension_loaded('pdo_mysql')) {
    fail('The pdo_mysql extension is required but not loaded.');
}

// Fail fast if we won't be able to write the config files we must update later.
// --schema-only writes none of them, so it runs happily against the read-only
// resources/config/ that secure-config.sh leaves behind.
if (!$schemaOnly) {
    foreach (['PDO.config.php', 'Session.config.php', 'config.php'] as $cf) {
        $p = $configDir . $cf;
        if ((is_file($p) && !is_writable($p)) || (!is_file($p) && !is_writable($configDir))) {
            fail("Config not writable: {$p} — the user running the installer must own resources/config/.");
        }
    }
}

out("AstrX CLI installer\n===================\n\n");

// ── 1. Database connection ──────────────────────────────────────────────────
// In --schema-only mode the install already exists, so PDO.config.php holds the
// right connection details; use them as the defaults instead of making the
// operator retype (and possibly mistype) them.
$existing = $schemaOnly ? readPDOConfig($configDir) : [];

$dbHost = resolve($args, 'db-host', 'Database host', $existing['db_host'] ?? 'localhost',           $noInput);
$dbName = resolve($args, 'db-name', 'Database name', $existing['db_name'] ?? 'content_manager',     $noInput);
$dbPort = (int) resolve($args, 'db-port', 'Database port', $existing['db_port'] ?? '3306',          $noInput);
$dbUser = resolve($args, 'db-user', 'Database username', $existing['db_username'] ?? 'user',        $noInput);
$dbPass = $schemaOnly && isset($existing['db_password']) && argVal($args, 'db-pass') === null
    ? $existing['db_password']
    : resolve($args, 'db-pass', 'Database password', null, $noInput, required: false, hidden: true);

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

if (!$schemaOnly) {
    $err = writePDO($configDir, $dbHost, $dbName, $dbUser, $dbPass, $dbPort);
    if ($err !== '') { fail($err); }
    out("Wrote PDO.config.php\n");
}

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

if ($schemaOnly) {
    out("\nSchema and migrations re-applied. Nothing else was touched (--schema-only).\n");
    exit(0);
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
