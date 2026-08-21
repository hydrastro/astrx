<?php
declare(strict_types=1);

/**
 * Schema convergence — the fresh install and the upgraded install must be the
 * same database. NEEDS A DATABASE; skips (exit 0) when it cannot reach one.
 *
 * WHY
 * ---
 * `src/setup/tables.sql` is a base schema with a run of former migrations
 * folded onto the end of it, and `src/setup/migrate_*.sql` holds 23 more that
 * the installer applies afterwards. So there are two ways to arrive at "the
 * current schema":
 *
 *   A. a fresh `tools/install.php` run — tables.sql, then every migration;
 *   B. a database that already existed, which the same installer upgrades —
 *      tables.sql RE-applied over live tables (every `CREATE TABLE` there fails
 *      with 42S01 and runSQL() swallows it as benign, so an existing table is
 *      never touched), then the migrations it has not recorded yet.
 *
 * Nothing checked that A and B agree. A column added by a migration but never
 * added to the base DDL — or added to the base DDL and never migrated — a
 * differing default, collation, sub-part, ON DELETE rule or AUTO_INCREMENT flag
 * silently produces two databases that both call themselves current.
 *
 * WHAT THIS ACTUALLY PROVES, AND WHAT IT CANNOT
 * ---------------------------------------------
 * The literal "oldest base schema, then every migration in order" is NOT
 * reconstructible from this repository, and no test should pretend otherwise:
 *
 *   * tables.sql has been rewritten in place across 54 commits. There is no
 *     artifact for "the base DDL as some installed site received it" — the file
 *     is one mutable document, and the migrations folded into its tail (the
 *     `CONSOLIDATED MIGRATIONS` section) were DELETED as standalone files, so
 *     the sequence that produced an existing install cannot be replayed.
 *   * Applied migrations have themselves been edited in place afterwards
 *     (migrate_zz_totp.sql, migrate_zz_invites.sql, migrate_zz_media.sql and
 *     migrate_zz_tipline.sql were all modified after shipping). runMigration()
 *     keys on sha256, so for a site that ran the earlier bytes those files can
 *     never run again — it aborts the installer instead. The historical B is
 *     therefore not merely unavailable, it is unreachable.
 *   * Nothing records WHICH tables.sql an install came from. The `migration`
 *     ledger tracks migrate_*.sql only.
 *
 * So B is reconstructed as far as the repo honestly permits: a database built
 * by the real installer with the migration set empty (today's base DDL alone,
 * which is the state of a site installed before any current migration existed),
 * which the real installer then upgrades. The base is today's base, not the
 * historical one — that is the limit. Within it the test is exact: it drives
 * `tools/install.php` itself, so migration discovery, ordering, the checksum
 * ledger and the benign-error rules are the shipped ones, not a copy.
 *
 * BE CLEAR ABOUT THE BLIND SPOT this leaves. Because B starts from TODAY's
 * tables.sql, the default run cannot see a change made to the base DDL of an
 * ALREADY-EXISTING table with no migration behind it — a new UNIQUE KEY on
 * `keyword`, a new column on `session`, a rewritten `resolved_navbar` body. On
 * a real upgrade those never arrive: runSQL() swallows `CREATE TABLE` and
 * `CREATE VIEW` on an existing object as SQLSTATE 42S01, so only ALTER,
 * DROP VIEW + CREATE VIEW, INSERT IGNORE and `CREATE TABLE IF NOT EXISTS` reach
 * a live database. To see that class, hand this test a real historical base:
 *
 *     ASTRX_SCHEMA_BASELINE_REV=<git-rev> php tests/schema_convergence_test.php
 *
 * which builds the "before" database from that revision's src/setup/tables.sql
 * (via `git show`) and upgrades it with today's installer. It is opt-in because
 * it needs git history that `actions/checkout` does not fetch by default, and
 * because which revision represents "an install in the field" is a judgement
 * call, not something a test can assume.
 *
 * Three assertions:
 *   1. fresh == upgraded, across the whole of information_schema.
 *   2. re-applying every migration to an already-current database changes
 *      nothing (each one is idempotent at the schema level) — the property the
 *      upgrade path depends on and the one thing that IS checkable about it.
 *   3. neither path is vacuous: floors on table/column/migration counts, so a
 *      comparison of two empty databases cannot pass.
 * It also prints the base-only vs fully-migrated delta: exactly what the
 * migrations add on top of tables.sql, i.e. what a future "fold them in" commit
 * has to reproduce.
 *
 * NOT covered: row data (seed pages, navbar entries) — only information_schema
 * is compared; and the historical divergence described above.
 *
 * WHAT IS NORMALISED, AND WHY
 * ---------------------------
 * A normaliser that hides a real difference is worse than no test, so this list
 * is deliberately short. Both snapshots come from THE SAME server in the same
 * run, which removes most environment dependence by construction: server
 * version, the default collation (utf8mb4_general_ci on MariaDB 10.11 vs
 * utf8mb4_uca1400_ai_ci on MariaDB 11), row_format and information_schema value
 * casing are identical on both sides and cancel out. Nothing is compared
 * against a hardcoded expectation, which is what makes this run unchanged on
 * 10.11 and 11.
 *
 *   * The database NAME, everywhere it appears in a value — the two databases
 *     must have different names, and VIEW_DEFINITION embeds it
 *     (`db`.`page`). Replaced with %DB%.
 *   * TABLES.AUTO_INCREMENT (the counter's CURRENT value) — a function of how
 *     many rows were inserted, not of the schema. The per-column
 *     EXTRA='auto_increment' flag IS compared, which is the real property.
 *   * TABLES.TABLE_ROWS / AVG_ROW_LENGTH / DATA_LENGTH / MAX_DATA_LENGTH /
 *     INDEX_LENGTH / DATA_FREE / CHECKSUM / CREATE_TIME / UPDATE_TIME /
 *     CHECK_TIME / VERSION — size, timing and row counts.
 *   * STATISTICS.CARDINALITY — a sampled row-count estimate.
 *   * VIEWS.DEFINER, TRIGGERS.DEFINER, ROUTINES.CREATED/LAST_ALTERED — the
 *     account that ran the installer and when.
 *   * information_schema COLUMN NAMES are upper-cased before use, so the array
 *     keys do not depend on the server's own casing convention. VALUES are left
 *     exactly as the server reports them.
 *
 * Everything else is compared verbatim: column name, ordinal position, data
 * type, length, precision/scale, nullability, default, EXTRA (auto_increment /
 * on update), per-column charset and collation, generation expression, comment;
 * index name, uniqueness, column order, sub-part, packing, nullability, type
 * and comment; foreign key name, column order, referenced table/column and
 * ON UPDATE / ON DELETE rules; view definition and its client charset; triggers;
 * stored routines; CHECK constraints; the storage engine, row format, and both
 * the table's and the database's own charset and collation.
 *
 * Run:  php tests/schema_convergence_test.php
 * With: ASTRX_TEST_DB_HOST / _PORT / _USER / _PASS, else resources/config/
 *       PDO.config.php, else DB_HOST/DB_PORT/DB_USER/DB_PASSWORD, else the
 *       CI service's 127.0.0.1 root/root.
 */

$ROOT = dirname(__DIR__);

/**
 * The pass/fail tally. Its siblings in tests/ keep it in `global $PASS, $FAIL`;
 * this one uses two typed static properties instead, because a global — and an
 * untyped function static — is `mixed` to static analysis: `$PASS++` and
 * `exit($FAIL === 0 …)` are both errors under `phpstan -l 10`, and phpstan.neon
 * analyses src/public/tools today but may not always stop there. Same surface:
 * a check() helper, a tally, exit(1) on failure.
 */
final class Tally
{
    public static int $pass = 0;
    public static int $fail = 0;
}

function check(string $label, bool $cond): void
{
    if ($cond) { Tally::$pass++; echo "  ok   - {$label}\n"; }
    else       { Tally::$fail++; echo "  FAIL - {$label}\n"; }
}

/** Print the skip banner and leave with 0 — the `lint` CI job has no database. */
function skip(string $why): never
{
    echo "\nskipped: no database — {$why}\n";
    echo "(this test needs MariaDB/MySQL; it runs in the module-matrix CI job)\n";
    exit(0);
}

// Scratch databases. Distinct prefix, dropped before and after. No name is a
// substring of another, so the %DB% substitution cannot corrupt another's
// values.
const DB_FRESH    = 'astrx_schemacmp_fresh';
const DB_UPGRADE  = 'astrx_schemacmp_upgrade';
const DB_BASELINE = 'astrx_schemacmp_baseline';

// ─────────────────────────────────────────────────────────────────────────────
// Connecting — every failure here is a SKIP, never a failure
// ─────────────────────────────────────────────────────────────────────────────

if (!extension_loaded('pdo_mysql')) {
    skip('the pdo_mysql extension is not loaded');
}
if (!function_exists('exec')) {
    skip('exec() is disabled, so tools/install.php cannot be driven');
}

/**
 * Connection candidates, most explicit first.
 *
 * @return list<array{host:string,port:int,user:string,pass:string,from:string}>
 */
function candidates(string $root): array
{
    $out = [];

    $envUser = (string) getenv('ASTRX_TEST_DB_USER');
    if ($envUser !== '') {
        $host = (string) getenv('ASTRX_TEST_DB_HOST');
        $port = (string) getenv('ASTRX_TEST_DB_PORT');
        $pass = getenv('ASTRX_TEST_DB_PASS');
        $out[] = [
            'host' => $host !== '' ? $host : '127.0.0.1',
            'port' => $port !== '' ? (int) $port : 3306,
            'user' => $envUser,
            'pass' => is_string($pass) ? $pass : '',
            'from' => 'ASTRX_TEST_DB_* environment',
        ];
    }

    // The installed application's own credentials. In the module-matrix job the
    // install step has already written this file with the service's details.
    $file = $root . '/resources/config/PDO.config.php';
    if (is_file($file)) {
        /** @var mixed $cfg */
        $cfg = @include $file;
        $pdoCfg = (is_array($cfg) && isset($cfg['PDO']) && is_array($cfg['PDO'])) ? $cfg['PDO'] : [];
        $host = $pdoCfg['db_host']     ?? null;
        $user = $pdoCfg['db_username'] ?? null;
        $pass = $pdoCfg['db_password'] ?? null;
        $port = $pdoCfg['db_port']     ?? null;
        if (is_string($host) && $host !== '' && is_string($user) && $user !== '') {
            $out[] = [
                'host' => $host,
                'port' => is_scalar($port) ? (int) $port : 3306,
                'user' => $user,
                'pass' => is_scalar($pass) ? (string) $pass : '',
                'from' => 'resources/config/PDO.config.php',
            ];
        }
    }

    $envUser2 = (string) getenv('DB_USER');
    if ($envUser2 !== '') {
        $host = (string) getenv('DB_HOST');
        $port = (string) getenv('DB_PORT');
        $pass = getenv('DB_PASSWORD');
        $out[] = [
            'host' => $host !== '' ? $host : '127.0.0.1',
            'port' => $port !== '' ? (int) $port : 3306,
            'user' => $envUser2,
            'pass' => is_string($pass) ? $pass : '',
            'from' => 'DB_* environment',
        ];
    }

    // What .github/workflows/ci.yml gives the mariadb service, then a bare local
    // server with no root password.
    $out[] = ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => 'root', 'from' => 'CI default (root/root)'];
    $out[] = ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => '',     'from' => 'local default (root, no password)'];

    return $out;
}

$conn    = null;
$source  = '';
$tried   = [];
$DB_HOST = '';
$DB_PORT = 0;
$DB_USER = '';
$DB_PASS = '';
foreach (candidates($ROOT) as $c) {
    try {
        $conn = new PDO(
            "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
        );
        $source  = $c['from'];
        $DB_HOST = $c['host'];
        $DB_PORT = $c['port'];
        $DB_USER = $c['user'];
        $DB_PASS = $c['pass'];
        break;
    } catch (PDOException $e) {
        $tried[] = $c['from'] . ' (' . $c['user'] . '@' . $c['host'] . ':' . $c['port'] . ')';
        $conn = null;
    }
}

if (!$conn instanceof PDO) {
    skip('tried ' . implode(', ', $tried));
}

$serverVersion = 'unknown';
try {
    $v = $conn->getAttribute(PDO::ATTR_SERVER_VERSION);
    if (is_scalar($v)) { $serverVersion = (string) $v; }
} catch (PDOException) {
    // Not worth failing over; the banner is cosmetic.
}

echo "AstrX schema convergence\n";
echo "server: {$serverVersion}  via {$source}\n";

// Creating scratch databases is a capability, not a schema property: without it
// there is nothing to compare, so skip rather than report a false failure.
$BASELINE_REV = (string) getenv('ASTRX_SCHEMA_BASELINE_REV');
$SCRATCH_DBS  = $BASELINE_REV !== ''
    ? [DB_FRESH, DB_UPGRADE, DB_BASELINE]
    : [DB_FRESH, DB_UPGRADE];

try {
    foreach ($SCRATCH_DBS as $d) {
        $conn->exec('DROP DATABASE IF EXISTS `' . $d . '`');
        // No CHARACTER SET clause on purpose: a hand-created database starts on
        // the server default, and tables.sql's opening `ALTER DATABASE CHARACTER
        // SET = utf8mb4 COLLATE = utf8mb4_unicode_ci` is then the thing that has
        // to move it. Every side starts identically, so the server's own default
        // (utf8mb4_general_ci on MariaDB 10.11, utf8mb4_uca1400_ai_ci on 11) is
        // irrelevant to the comparison.
        $conn->exec('CREATE DATABASE `' . $d . '`');
    }
} catch (PDOException $e) {
    skip('connected, but cannot create scratch databases: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
// Driving the real installer
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run `tools/install.php --schema-only` from $installerRoot against $db.
 *
 * --schema-only applies tables.sql + every migration it discovers and stops:
 * it writes no config file, creates no administrator and generates no secret,
 * so it is safe to point at a scratch database.
 *
 * @return array{code:int,output:string}
 */
function installer(string $installerRoot, string $host, int $port, string $db, string $user, string $pass): array
{
    $cmd = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($installerRoot . '/tools/install.php')
        . ' --no-input --schema-only'
        . ' --db-host=' . escapeshellarg($host)
        . ' --db-port=' . escapeshellarg((string) $port)
        . ' --db-name=' . escapeshellarg($db)
        . ' --db-user=' . escapeshellarg($user)
        . ' --db-pass=' . escapeshellarg($pass)
        . ' 2>&1';

    $lines = [];
    $code  = 0;
    exec($cmd, $lines, $code);

    return ['code' => $code, 'output' => implode("\n", $lines)];
}

/**
 * A throwaway installer root holding the SAME tools/install.php and a tables.sql
 * but no migrate_*.sql at all, so the shipped installer applies the base DDL and
 * nothing else. This is how the "before" database is built without hand-rolling
 * a second SQL runner: install.php resolves everything from dirname(__DIR__), so
 * a directory with the two files it needs is a complete installer root.
 *
 * $tablesSql overrides the schema body (used by the baseline-revision mode).
 */
function baseOnlyRoot(string $realRoot, ?string $tablesSql = null): string
{
    $tmp = rtrim(sys_get_temp_dir(), '/') . '/astrx-schemacmp-base-' . bin2hex(random_bytes(6));
    foreach (['/tools', '/src/setup', '/resources/config'] as $d) {
        if (!@mkdir($tmp . $d, 0o700, true) && !is_dir($tmp . $d)) {
            throw new RuntimeException("Could not create {$tmp}{$d}");
        }
    }
    if (!@copy($realRoot . '/tools/install.php', $tmp . '/tools/install.php')) {
        throw new RuntimeException('Could not copy tools/install.php into the scratch installer root');
    }
    $ok = $tablesSql === null
        ? @copy($realRoot . '/src/setup/tables.sql', $tmp . '/src/setup/tables.sql')
        : (bool) @file_put_contents($tmp . '/src/setup/tables.sql', $tablesSql);
    if (!$ok) {
        throw new RuntimeException('Could not place tables.sql in the scratch installer root');
    }
    return $tmp;
}

/**
 * `git show <rev>:src/setup/tables.sql`, or null when git or the revision is
 * unavailable (a shallow CI checkout has no history to show).
 *
 * ONE edit is made to the historical file: a leading `USE <database>;` is
 * removed. Revisions before 2026-08 opened with a hardcoded `USE
 * content_manager`, which cannot run against a scratch database of any other
 * name — the current tables.sql dropped that line for exactly this reason, and
 * the installer connects with a DSN already scoped to the target database, so
 * removing it reproduces what an install with db_name=content_manager got.
 * Nothing else in the historical schema is touched.
 */
function tablesSqlAtRevision(string $root, string $rev): ?string
{
    $lines = [];
    $code  = 0;
    exec(
        'git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($rev . ':src/setup/tables.sql') . ' 2>/dev/null',
        $lines,
        $code,
    );
    if ($code !== 0 || $lines === []) {
        return null;
    }
    $sql = implode("\n", $lines) . "\n";

    return (string) preg_replace('/^\s*USE\s+`?[A-Za-z0-9_]+`?\s*;\s*$/mi', '', $sql);
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $p = $dir . '/' . $e;
        is_dir($p) ? rmTree($p) : @unlink($p);
    }
    @rmdir($dir);
}

// ─────────────────────────────────────────────────────────────────────────────
// The snapshot
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run a query and return its rows with UPPER-CASED keys and string|null values.
 *
 * @param  list<string> $params
 * @return list<array<string,string|null>>
 */
function rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    /** @var mixed $row */
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) { continue; }
        $clean = [];
        /** @var mixed $v */
        foreach ($row as $k => $v) {
            $clean[strtoupper((string) $k)] = match (true) {
                $v === null   => null,
                is_scalar($v) => (string) $v,
                default       => '<' . gettype($v) . '>',
            };
        }
        $out[] = $clean;
    }
    $stmt->closeCursor();

    return $out;
}

/**
 * Fold rows into the flat "OBJECT \t ATTRIBUTE => value" map that is compared.
 *
 * $idCols name the object (`COLUMN page.module`), $attrCols are the attributes
 * asserted on. The database name is replaced with %DB% in every value: it is
 * the one genuinely environment-dependent string, and it appears inside view
 * definitions as well as in schema columns.
 *
 * @param array<string,string|null>       $into
 * @param list<array<string,string|null>> $rowSet
 * @param list<string>                    $idCols
 * @param list<string>                    $attrCols
 */
function collect(array &$into, string $kind, array $rowSet, array $idCols, array $attrCols, string $dbName): void
{
    foreach ($rowSet as $row) {
        $idParts = [];
        foreach ($idCols as $c) { $idParts[] = $row[$c] ?? '?'; }
        $object = $idParts === [] ? $kind : $kind . ' ' . implode('.', $idParts);

        foreach ($attrCols as $c) {
            $v = $row[$c] ?? null;
            if (is_string($v)) { $v = str_replace($dbName, '%DB%', $v); }
            $into[$object . "\t" . $c] = $v;
        }
    }
}

/**
 * Every schema fact about $dbName worth asserting on, as one flat map.
 *
 * @return array<string,string|null>
 */
function snapshot(PDO $pdo, string $dbName): array
{
    $s = [];

    // The database's own defaults. tables.sql's first statement moves them; if
    // that ever stops working, every table below inherits the wrong charset.
    collect($s, 'SCHEMA', rows(
        $pdo,
        'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
           FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
        [$dbName],
    ), [], ['DEFAULT_CHARACTER_SET_NAME', 'DEFAULT_COLLATION_NAME'], $dbName);

    // Tables and views. AUTO_INCREMENT (the counter), row counts, byte sizes and
    // timestamps are not selected — see the header for why.
    $tables = rows(
        $pdo,
        'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, ROW_FORMAT, TABLE_COLLATION, TABLE_COMMENT
           FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
        [$dbName],
    );
    // The table's charset is not a column of its own anywhere in
    // information_schema; it is the leading token of the collation.
    foreach ($tables as $i => $t) {
        $coll = $t['TABLE_COLLATION'] ?? null;
        $tables[$i]['TABLE_CHARSET'] = is_string($coll) ? strstr($coll, '_', true) ?: $coll : null;
    }
    collect($s, 'TABLE', $tables, ['TABLE_NAME'], [
        'TABLE_TYPE', 'ENGINE', 'ROW_FORMAT', 'TABLE_CHARSET', 'TABLE_COLLATION', 'TABLE_COMMENT',
    ], $dbName);

    collect($s, 'COLUMN', rows(
        $pdo,
        'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE,
                CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
                DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE, COLUMN_KEY,
                EXTRA, COLUMN_COMMENT, GENERATION_EXPRESSION
           FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME, ORDINAL_POSITION',
        [$dbName],
    ), ['TABLE_NAME', 'COLUMN_NAME'], [
        'ORDINAL_POSITION', 'COLUMN_DEFAULT', 'IS_NULLABLE', 'DATA_TYPE',
        'CHARACTER_MAXIMUM_LENGTH', 'CHARACTER_OCTET_LENGTH', 'NUMERIC_PRECISION', 'NUMERIC_SCALE',
        'DATETIME_PRECISION', 'CHARACTER_SET_NAME', 'COLLATION_NAME', 'COLUMN_TYPE', 'COLUMN_KEY',
        'EXTRA', 'COLUMN_COMMENT', 'GENERATION_EXPRESSION',
    ], $dbName);

    // One row per index COLUMN, keyed by position, so a reordered composite
    // index reads as "SEQ_IN_INDEX 2 is a different column" rather than as an
    // opaque whole-index difference. CARDINALITY is not selected.
    collect($s, 'INDEX', rows(
        $pdo,
        'SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, COLLATION,
                SUB_PART, PACKED, NULLABLE, INDEX_TYPE, COMMENT, INDEX_COMMENT
           FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        [$dbName],
    ), ['TABLE_NAME', 'INDEX_NAME', 'SEQ_IN_INDEX'], [
        'COLUMN_NAME', 'NON_UNIQUE', 'COLLATION', 'SUB_PART', 'PACKED', 'NULLABLE',
        'INDEX_TYPE', 'COMMENT', 'INDEX_COMMENT',
    ], $dbName);

    // Foreign keys, including the constraint NAME: a differently-named
    // constraint is a real difference, because a later migration that does
    // `DROP FOREIGN KEY <name>` only finds one of them. Unnamed constraints get
    // <table>_ibfk_N, numbered in creation order, so a name difference is
    // usually an ORDER difference — worth seeing, not worth normalising away.
    collect($s, 'FK', rows(
        $pdo,
        'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME,
                k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                r.UPDATE_RULE, r.DELETE_RULE, r.MATCH_OPTION
           FROM information_schema.KEY_COLUMN_USAGE k
           JOIN information_schema.REFERENTIAL_CONSTRAINTS r
             ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
            AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
            AND r.TABLE_NAME        = k.TABLE_NAME
          WHERE k.CONSTRAINT_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
          ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
        [$dbName],
    ), ['TABLE_NAME', 'CONSTRAINT_NAME', 'ORDINAL_POSITION'], [
        'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME',
        'UPDATE_RULE', 'DELETE_RULE', 'MATCH_OPTION',
    ], $dbName);

    // resolved_page / resolved_navbar are dropped and recreated by migrations
    // (api_enabled, module, visibility all widened them), which makes the view
    // body one of the likeliest things to diverge between the two paths.
    // DEFINER is excluded: it is whoever ran the installer.
    collect($s, 'VIEW', rows(
        $pdo,
        'SELECT TABLE_NAME, VIEW_DEFINITION, CHECK_OPTION, IS_UPDATABLE, SECURITY_TYPE,
                CHARACTER_SET_CLIENT, COLLATION_CONNECTION
           FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
        [$dbName],
    ), ['TABLE_NAME'], [
        'VIEW_DEFINITION', 'CHECK_OPTION', 'IS_UPDATABLE', 'SECURITY_TYPE',
        'CHARACTER_SET_CLIENT', 'COLLATION_CONNECTION',
    ], $dbName);

    // Empty in this schema today. Present so that the first trigger or stored
    // routine anyone adds is covered without remembering to extend this file.
    collect($s, 'TRIGGER', rows(
        $pdo,
        'SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING,
                ACTION_ORDER, ACTION_ORIENTATION, ACTION_STATEMENT, SQL_MODE
           FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME',
        [$dbName],
    ), ['TRIGGER_NAME'], [
        'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'ACTION_ORDER',
        'ACTION_ORIENTATION', 'ACTION_STATEMENT', 'SQL_MODE',
    ], $dbName);

    collect($s, 'ROUTINE', rows(
        $pdo,
        'SELECT SPECIFIC_NAME, ROUTINE_TYPE, DTD_IDENTIFIER, ROUTINE_BODY, ROUTINE_DEFINITION,
                IS_DETERMINISTIC, SQL_DATA_ACCESS, SECURITY_TYPE, PARAMETER_STYLE, SQL_MODE
           FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY SPECIFIC_NAME',
        [$dbName],
    ), ['SPECIFIC_NAME'], [
        'ROUTINE_TYPE', 'DTD_IDENTIFIER', 'ROUTINE_BODY', 'ROUTINE_DEFINITION',
        'IS_DETERMINISTIC', 'SQL_DATA_ACCESS', 'SECURITY_TYPE', 'PARAMETER_STYLE', 'SQL_MODE',
    ], $dbName);

    try {
        collect($s, 'CHECK', rows(
            $pdo,
            'SELECT TABLE_NAME, CONSTRAINT_NAME, CHECK_CLAUSE
               FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ?
              ORDER BY TABLE_NAME, CONSTRAINT_NAME',
            [$dbName],
        ), ['TABLE_NAME', 'CONSTRAINT_NAME'], ['CHECK_CLAUSE'], $dbName);
    } catch (PDOException) {
        // information_schema.CHECK_CONSTRAINTS predates neither MariaDB 10.2 nor
        // MySQL 8.0.16, but an older server should skip this section rather than
        // fail the whole comparison — and it is skipped on BOTH sides equally.
    }

    return $s;
}

/**
 * Human-readable differences between two snapshots. Keys are OBJECT \t ATTR, so
 * every line names the object, the attribute and both values.
 *
 * @param  array<string,string|null> $a
 * @param  array<string,string|null> $b
 * @return list<array{object:string,attr:string,side:string,text:string}>
 */
function schemaDiff(array $a, array $b, string $labelA, string $labelB): array
{
    $keys = array_keys($a + $b);
    sort($keys);

    $out = [];
    foreach ($keys as $k) {
        $inA = array_key_exists($k, $a);
        $inB = array_key_exists($k, $b);
        [$object, $attr] = array_pad(explode("\t", $k, 2), 2, '');

        if ($inA && !$inB) {
            $out[] = [
                'object' => $object, 'attr' => $attr, 'side' => $labelA,
                'text'   => sprintf('%-52s %-26s only in %s (= %s)', $object, $attr, $labelA, show($a[$k])),
            ];
            continue;
        }
        if (!$inA && $inB) {
            $out[] = [
                'object' => $object, 'attr' => $attr, 'side' => $labelB,
                'text'   => sprintf('%-52s %-26s only in %s (= %s)', $object, $attr, $labelB, show($b[$k])),
            ];
            continue;
        }
        if ($a[$k] !== $b[$k]) {
            $out[] = [
                'object' => $object, 'attr' => $attr, 'side' => 'both',
                'text'   => sprintf(
                    '%-52s %-26s %s=%s  %s=%s',
                    $object, $attr, $labelA, show($a[$k]), $labelB, show($b[$k]),
                ) . firstDivergence($a[$k], $b[$k]),
            ];
        }
    }

    return $out;
}

/** Render a value so NULL and the literal string 'NULL' cannot be confused. */
function show(?string $v): string
{
    if ($v === null)      { return '<NULL>'; }
    if (strlen($v) <= 90) { return "'" . $v . "'"; }
    return "'" . substr($v, 0, 87) . "…' (" . strlen($v) . ' chars)';
}

/**
 * For long values (view bodies), point at the first character that differs and
 * show a window around it — a truncated pair of 3 KB view definitions otherwise
 * proves nothing.
 */
function firstDivergence(?string $a, ?string $b): string
{
    if (!is_string($a) || !is_string($b) || (strlen($a) <= 90 && strlen($b) <= 90)) {
        return '';
    }
    $n   = min(strlen($a), strlen($b));
    $i   = 0;
    while ($i < $n && $a[$i] === $b[$i]) { $i++; }
    $from = max(0, $i - 40);

    return "\n" . str_repeat(' ', 6) . "first differs at byte {$i}:\n"
        . str_repeat(' ', 8) . '…' . substr($a, $from, 110) . "…\n"
        . str_repeat(' ', 8) . '…' . substr($b, $from, 110) . '…';
}

/**
 * Print a diff: a tally by kind+attribute first, so a 200-line difference is
 * legible at a glance, then the lines themselves.
 *
 * @param list<array{object:string,attr:string,side:string,text:string}> $diff
 */
function printDiff(array $diff, int $limit = 40): void
{
    if (count($diff) > 10) {
        $tally = [];
        foreach ($diff as $d) {
            [$kind] = explode(' ', $d['object'], 2);
            $tally[$kind . ' ' . $d['attr']] = ($tally[$kind . ' ' . $d['attr']] ?? 0) + 1;
        }
        arsort($tally);
        echo "    by attribute:\n";
        foreach (array_slice($tally, 0, 12, true) as $what => $n) {
            echo sprintf("      %4d x %s\n", $n, $what);
        }
    }
    foreach (array_slice($diff, 0, $limit) as $d) {
        echo '    ' . $d['text'] . "\n";
    }
    if (count($diff) > $limit) {
        echo '    … and ' . (count($diff) - $limit) . " more\n";
    }
}

/** @param array<string,string|null> $snap */
function countObjects(array $snap, string $kind): int
{
    $seen = [];
    foreach (array_keys($snap) as $k) {
        [$object] = explode("\t", $k, 2);
        if (str_starts_with($object, $kind . ' ')) { $seen[$object] = true; }
    }
    return count($seen);
}

// ─────────────────────────────────────────────────────────────────────────────
// The three databases
// ─────────────────────────────────────────────────────────────────────────────

/** @var list<string> $tempRoots */
$tempRoots = [];
try {
    // ── A. Fresh install: tables.sql + every migration, in one installer run ──
    echo "\n== A: fresh install ({$DB_HOST}:{$DB_PORT}/" . DB_FRESH . ") ==\n";
    $freshRun = installer($ROOT, $DB_HOST, $DB_PORT, DB_FRESH, $DB_USER, $DB_PASS);
    check('fresh installer run exits 0', $freshRun['code'] === 0);
    if ($freshRun['code'] !== 0) {
        echo "    installer output:\n" . preg_replace('/^/m', '      ', $freshRun['output']) . "\n";
    }

    $pdoFresh = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname=" . DB_FRESH . ';charset=utf8mb4',
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $snapFresh = snapshot($pdoFresh, DB_FRESH);

    // Floors, in the spirit of the workflow's MIN_MODULES guard: two empty
    // databases are trivially identical, and that pass would mean nothing.
    $tableCount  = countObjects($snapFresh, 'TABLE');
    $columnCount = countObjects($snapFresh, 'COLUMN');
    echo "    {$tableCount} tables/views, {$columnCount} columns, "
        . count($snapFresh) . " attributes captured\n";
    check("fresh install created a real schema ({$tableCount} tables/views, expected >= 40)", $tableCount >= 40);
    check("fresh install has a real column set ({$columnCount} columns, expected >= 300)", $columnCount >= 300);

    // Every migration on disk must be in the ledger: a migration that silently
    // did not run would make both paths equally wrong, and equally green.
    $onDisk = count(glob($ROOT . '/src/setup/migrate_*.sql') ?: []);
    $ledger = rows($pdoFresh, 'SELECT file_name FROM `migration` ORDER BY file_name');
    check(
        "every migrate_*.sql is recorded in the ledger ({$onDisk} on disk, " . count($ledger) . ' recorded)',
        $onDisk > 0 && count($ledger) === $onDisk,
    );

    // ── B1. Base only: today's tables.sql, with no migrations in the tree ─────
    echo "\n== B1: base schema only (tables.sql, no migrations) ==\n";
    $baseRoot    = baseOnlyRoot($ROOT);
    $tempRoots[] = $baseRoot;
    $baseRun     = installer($baseRoot, $DB_HOST, $DB_PORT, DB_UPGRADE, $DB_USER, $DB_PASS);
    check('base-only installer run exits 0', $baseRun['code'] === 0);
    if ($baseRun['code'] !== 0) {
        echo "    installer output:\n" . preg_replace('/^/m', '      ', $baseRun['output']) . "\n";
    }

    $pdoUpgrade = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname=" . DB_UPGRADE . ';charset=utf8mb4',
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $snapBase = snapshot($pdoUpgrade, DB_UPGRADE);
    check('the base-only run applied no migrations',
        rows($pdoUpgrade, 'SELECT file_name FROM `migration`') === []);
    echo '    ' . countObjects($snapBase, 'TABLE') . ' tables/views before the upgrade'
        . ' (' . countObjects($snapFresh, 'TABLE') . " after)\n";

    // ── B2. Upgrade it with the real installer ───────────────────────────────
    echo "\n== B2: upgrade that database with the shipped installer ==\n";
    $upgradeRun = installer($ROOT, $DB_HOST, $DB_PORT, DB_UPGRADE, $DB_USER, $DB_PASS);
    check('upgrade installer run exits 0', $upgradeRun['code'] === 0);
    if ($upgradeRun['code'] !== 0) {
        echo "    installer output:\n" . preg_replace('/^/m', '      ', $upgradeRun['output']) . "\n";
    }
    $snapUpgrade = snapshot($pdoUpgrade, DB_UPGRADE);

    // ── The assertion this file exists for ───────────────────────────────────
    echo "\n== fresh == upgraded ==\n";
    $diff = schemaDiff($snapFresh, $snapUpgrade, 'fresh', 'upgraded');
    check(
        'a fresh install and an upgraded install have identical schemas ('
            . count($snapFresh) . ' attributes compared, ' . count($diff) . ' differences)',
        $diff === [],
    );
    printDiff($diff);

    // ── Idempotence: every migration re-applied to a current database ────────
    // Clearing the ledger is exactly the state an upgrading install is in: the
    // rows are absent, so runMigration() executes the file against a database
    // that may already contain everything it adds. If any migration is not a
    // schema-level no-op there, the upgrade path cannot converge.
    echo "\n== every migration is a no-op against an already-current database ==\n";
    $pdoFresh->exec('DELETE FROM `migration`');
    $rerun = installer($ROOT, $DB_HOST, $DB_PORT, DB_FRESH, $DB_USER, $DB_PASS);
    check('re-run installer exits 0', $rerun['code'] === 0);
    if ($rerun['code'] !== 0) {
        echo "    installer output:\n" . preg_replace('/^/m', '      ', $rerun['output']) . "\n";
    }
    $snapRerun = snapshot($pdoFresh, DB_FRESH);
    $diffRerun = schemaDiff($snapFresh, $snapRerun, 'first-run', 're-run');
    check(
        'applying tables.sql + every migration a second time changes nothing ('
            . count($diffRerun) . ' differences)',
        $diffRerun === [],
    );
    printDiff($diffRerun);

    // ── What the migrations actually add on top of the base DDL ─────────────
    // Not an assertion: with the current layout the migrations are NOT folded
    // into tables.sql, so this delta is expected to be large. It is printed
    // because it is the exact list a "fold the migrations in" commit must
    // reproduce, and because an entry here that looks like a MODIFY of
    // something tables.sql already defines means the base DDL and a migration
    // disagree about the same object.
    echo "\n== base DDL vs fully migrated (informational) ==\n";
    $delta       = schemaDiff($snapBase, $snapUpgrade, 'base-only', 'migrated');
    $addedTables = [];
    $changed     = [];
    foreach ($delta as $d) {
        if ($d['side'] === 'migrated' && str_starts_with($d['object'], 'TABLE ')) {
            $addedTables[] = substr($d['object'], 6);
        }
        // Present in BOTH databases with different values: the base defines the
        // object and a migration changes it.
        if ($d['side'] === 'both') {
            $changed[] = $d;
        }
    }
    $addedTables = array_values(array_unique($addedTables));
    echo '    migrations add ' . count($addedTables) . " tables/views: "
        . implode(', ', array_slice($addedTables, 0, 30)) . "\n";
    echo '    migrations CHANGE ' . count($changed) . " attribute(s) the base DDL already defines:\n";
    printDiff($changed, 60);

    // ── Opt-in: a REAL historical base, replayed forward ────────────────────
    // The only way to see a base-DDL edit that no migration carries. Off unless
    // ASTRX_SCHEMA_BASELINE_REV names a git revision, because CI checks out one
    // commit and because "which revision is out there" is a human judgement.
    if ($BASELINE_REV !== '') {
        echo "\n== baseline: tables.sql at {$BASELINE_REV}, then upgraded to HEAD ==\n";
        $oldTables = tablesSqlAtRevision($ROOT, $BASELINE_REV);
        check("git show {$BASELINE_REV}:src/setup/tables.sql succeeded", $oldTables !== null);

        if ($oldTables !== null) {
            $oldRoot     = baseOnlyRoot($ROOT, $oldTables);
            $tempRoots[] = $oldRoot;

            $oldRun = installer($oldRoot, $DB_HOST, $DB_PORT, DB_BASELINE, $DB_USER, $DB_PASS);
            check("the {$BASELINE_REV} base schema applies to an empty database", $oldRun['code'] === 0);
            if ($oldRun['code'] !== 0) {
                // A pre-2026-08 tables.sql opens with `USE content_manager;`,
                // which cannot run against a differently-named database. That is
                // a property of the revision, not of this test.
                echo "    installer output:\n" . preg_replace('/^/m', '      ', $oldRun['output']) . "\n";
            } else {
                $oldUpgrade = installer($ROOT, $DB_HOST, $DB_PORT, DB_BASELINE, $DB_USER, $DB_PASS);
                check('the shipped installer upgrades it without aborting', $oldUpgrade['code'] === 0);
                if ($oldUpgrade['code'] !== 0) {
                    echo "    installer output:\n" . preg_replace('/^/m', '      ', $oldUpgrade['output']) . "\n";
                }

                $pdoBaseline = new PDO(
                    "mysql:host={$DB_HOST};port={$DB_PORT};dbname=" . DB_BASELINE . ';charset=utf8mb4',
                    $DB_USER,
                    $DB_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );
                $diffOld = schemaDiff(snapshot($pdoBaseline, DB_BASELINE), $snapFresh, 'upgraded', 'fresh');
                check(
                    "an install from {$BASELINE_REV} upgrades to the fresh schema ("
                        . count($diffOld) . ' differences)',
                    $diffOld === [],
                );
                printDiff($diffOld);
            }
        }
    }
} finally {
    foreach ($tempRoots as $r) { rmTree($r); }
    foreach ($SCRATCH_DBS as $d) {
        try {
            $conn->exec('DROP DATABASE IF EXISTS `' . $d . '`');
        } catch (PDOException $e) {
            echo '  note - could not drop the scratch database ' . $d . ': ' . $e->getMessage() . "\n";
        }
    }
}

echo "\n" . Tally::$pass . ' passed, ' . Tally::$fail . " failed\n";
exit(Tally::$fail === 0 ? 0 : 1);
