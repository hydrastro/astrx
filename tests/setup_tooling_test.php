<?php
declare(strict_types=1);

/**
 * Setup + module tooling — NO AstrX bootstrap, no database.
 *
 * Pure-function coverage of the three places the install/teardown tooling was
 * quietly wrong:
 *
 *  1. runSQL() swallowed the whole of SQLSTATE 23000 to make tables.sql
 *     re-runnable. 23000 also covers foreign-key (1451/1452) and NOT-NULL
 *     (1048) violations, so a migration whose INSERT failed integrity was
 *     recorded as applied and could never run again.
 *  2. `ADD COLUMN IF NOT EXISTS` is MariaDB-only. On MySQL 8 it is
 *     ER_PARSE_ERROR (42000), which runSQL deliberately does not swallow, so
 *     the install aborted on the first of the 33 such clauses.
 *  3. `module.php purge` dropped tables that only a migration creates, left the
 *     migration's row saying "applied", and told the operator to reinstall the
 *     schema — which then skipped those migrations and restored nothing.
 *
 * Run:  php tests/setup_tooling_test.php
 */

$ROOT = dirname(__DIR__);

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
    check(
        $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')',
        $expected === $actual,
    );
}

/**
 * Load the pure helpers out of a CLI tool without executing its main body.
 * Both tools exit early unless PHP_SAPI === 'cli', but they also connect to a
 * database and parse argv, so we lift just the function definitions.
 */
function loadFunctions(string $file, string $from, string $to): void
{
    $src   = (string) file_get_contents($file);
    $start = strpos($src, $from);
    $end   = strpos($src, $to);
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException("Could not slice {$file} between markers");
    }
    eval(substr($src, $start, $end - $start));
}

// ── 1. runSQL()'s error classification ───────────────────────────────────────

// portableDdl, serverHasIfNotExistsDdl, schemaObjectExists, splitTopLevel and
// sqlErrorIsBenign all sit between these two markers.
loadFunctions(
    $ROOT . '/tools/install.php',
    'function portableDdl(',
    'function ensureMigrationTable(',
);

/** Build a PDOException carrying a specific SQLSTATE + driver code. */
function pdoError(string $sqlState, int $driverCode, string $message = 'test'): PDOException
{
    $e = new PDOException($message);
    $e->errorInfo = [$sqlState, $driverCode, $message];
    // PDOException::getCode() reports the SQLSTATE for driver errors.
    $ref  = new ReflectionClass(Exception::class);
    $prop = $ref->getProperty('code');
    $prop->setAccessible(true);
    $prop->setValue($e, $sqlState);
    return $e;
}

echo "\n== runSQL() error classification ==\n";

check('42S01 (table already exists) is benign',
    sqlErrorIsBenign(pdoError('42S01', 1050)));
check('42S21 (column already exists) is benign',
    sqlErrorIsBenign(pdoError('42S21', 1060)));
check('23000/1062 (duplicate entry) is benign',
    sqlErrorIsBenign(pdoError('23000', 1062)));
check('23000/1022 (duplicate key) is benign',
    sqlErrorIsBenign(pdoError('23000', 1022)));

check('23000/1452 (foreign key violation) is NOT benign',
    !sqlErrorIsBenign(pdoError('23000', 1452)));
check('23000/1451 (foreign key violation on delete) is NOT benign',
    !sqlErrorIsBenign(pdoError('23000', 1451)));
check('23000/1048 (column cannot be null) is NOT benign',
    !sqlErrorIsBenign(pdoError('23000', 1048)));
check('42000 (parse/access error) is NOT benign',
    !sqlErrorIsBenign(pdoError('42000', 1064)));
check('a 23000 with no driver code is NOT benign',
    !sqlErrorIsBenign(pdoError('23000', 0)));

// ── 2. MariaDB-only DDL → portable DDL ───────────────────────────────────────

echo "\n== ALTER TABLE clause splitting ==\n";

eq('a plain clause list splits on its commas',
    ['ADD COLUMN `a` INT', ' ADD COLUMN `b` INT'],
    splitTopLevel('ADD COLUMN `a` INT, ADD COLUMN `b` INT'));

eq('a comma inside DECIMAL(10,2) does not split the clause',
    ['ADD COLUMN `a` DECIMAL(10,2)'],
    splitTopLevel('ADD COLUMN `a` DECIMAL(10,2)'));

eq("a comma inside an index's column list does not split the clause",
    ['ADD INDEX `i` (`a`, `b`)'],
    splitTopLevel('ADD INDEX `i` (`a`, `b`)'));

eq('a comma inside a string literal does not split the clause',
    ["ADD COLUMN `a` VARCHAR(8) DEFAULT 'x,y'"],
    splitTopLevel("ADD COLUMN `a` VARCHAR(8) DEFAULT 'x,y'"));

echo "\n== portableDdl() rewriting ==\n";

/**
 * A PDO stand-in that reports a MySQL server version and answers the
 * information_schema probe from a fixed set of existing objects.
 */
final class FakePdo extends PDO
{
    /** @param list<string> $existing lower-cased "table.name" pairs that exist */
    public function __construct(
        private readonly string $version,
        private readonly array $existing = [],
    ) {
        parent::__construct('sqlite::memory:');
    }

    #[\ReturnTypeWillChange]
    public function getAttribute(int $attribute): mixed
    {
        return $attribute === PDO::ATTR_SERVER_VERSION ? $this->version : parent::getAttribute($attribute);
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        // information_schema is not a thing in SQLite; answer from $existing.
        return new FakeStatement($this->existing);
    }
}

final class FakeStatement extends PDOStatement
{
    private bool $found = false;

    /** @param list<string> $existing */
    public function __construct(private readonly array $existing) {}

    #[\ReturnTypeWillChange]
    public function execute(?array $params = null): bool
    {
        $table = is_array($params) ? (string) ($params[':t'] ?? '') : '';
        $name  = is_array($params) ? (string) ($params[':n'] ?? '') : '';
        $this->found = in_array(strtolower($table . '.' . $name), $this->existing, true);
        return true;
    }

    #[\ReturnTypeWillChange]
    public function fetchColumn(int $column = 0): mixed
    {
        return $this->found ? 1 : false;
    }

    #[\ReturnTypeWillChange]
    public function closeCursor(): bool { return true; }
}

$maria = new FakePdo('11.4.2-MariaDB-log');
$mysql = new FakePdo('8.0.36', ['user.totp_secret']);

$mariaOnly = 'ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `totp_secret` VARCHAR(64) NULL';
eq('MariaDB gets the statement untouched', $mariaOnly, portableDdl($maria, $mariaOnly));

$multi = "ALTER TABLE `user`\n"
       . "    ADD COLUMN IF NOT EXISTS `totp_secret` VARCHAR(64) NULL,\n"
       . "    ADD COLUMN IF NOT EXISTS `totp_enabled` TINYINT NOT NULL DEFAULT 0";
$rewritten = portableDdl($mysql, $multi);

check('MySQL gets a rewritten statement', is_string($rewritten));
check('…with no MariaDB-only IF NOT EXISTS left in it',
    is_string($rewritten) && stripos($rewritten, 'IF NOT EXISTS') === false);
check('…keeping the clause whose column is missing',
    is_string($rewritten) && str_contains($rewritten, '`totp_enabled`'));
check('…and dropping the clause whose column already exists',
    is_string($rewritten) && !str_contains($rewritten, '`totp_secret`'));

$allPresent = 'ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `totp_secret` VARCHAR(64) NULL';
eq('a statement whose every clause is satisfied is skipped entirely',
    null, portableDdl($mysql, $allPresent));

$untouched = 'INSERT IGNORE INTO `page` (url_id) VALUES (\'WORDING_X\')';
eq('a non-ALTER statement is passed through', $untouched, portableDdl($mysql, $untouched));

$indexStmt = 'ALTER TABLE `session` ADD INDEX IF NOT EXISTS `idx_session_timestamp` (`timestamp`)';
$indexOut  = portableDdl($mysql, $indexStmt);
check('an ADD INDEX IF NOT EXISTS is rewritten too',
    is_string($indexOut) && $indexOut === 'ALTER TABLE `session` ADD INDEX `idx_session_timestamp` (`timestamp`)');

// ── 3. purge ↔ migration bookkeeping ─────────────────────────────────────────

loadFunctions(
    $ROOT . '/tools/module.php',
    'function m_dropped_tables(',
    'function m_forget_migrations(',
);

echo "\n== purge: which migrations must be forgotten ==\n";

$setupDir   = $ROOT . '/src/setup/';
$modulesDir = $setupDir . 'modules/';

/**
 * @return list<string>
 */
function migrationsFor(string $modulesDir, string $setupDir, string $module): array
{
    $down = $modulesDir . $module . '.down.sql';
    if (!is_file($down)) { return []; }
    return m_module_migrations($setupDir, m_dropped_tables($down), $module);
}

check('content: its teardown drops content_page + content_link',
    m_dropped_tables($modulesDir . 'content.down.sql') === ['content_link', 'content_page']);

foreach (['content' => 'migrate_zz_content_module.sql',
          'media'   => 'migrate_zz_media.sql',
          'tipline' => 'migrate_zz_tipline.sql'] as $module => $expected) {
    check(
        "{$module}: purge forgets {$expected}, so --schema-only recreates its tables",
        in_array($expected, migrationsFor($modulesDir, $setupDir, $module), true),
    );
}

// canary/downloads/mirrors own no table at all — their PAGES are the only thing
// purge destroys, and those come from a migration too.
foreach (['canary' => 'migrate_zz_canary.sql',
          'downloads' => 'migrate_zz_downloads.sql',
          'mirrors' => 'migrate_zz_mirrors.sql'] as $module => $expected) {
    check(
        "{$module}: purge forgets {$expected}, so its pages come back",
        in_array($expected, migrationsFor($modulesDir, $setupDir, $module), true),
    );
}

check('a module whose schema is entirely in tables.sql forgets nothing',
    migrationsFor($modulesDir, $setupDir, 'blocklist') === []);

check('the restore path the tool prints exists',
    str_contains((string) file_get_contents($ROOT . '/tools/install.php'), "hasFlag(\$args, 'schema-only')"));

// ── 4. module.php flag flipping ──────────────────────────────────────────────

require_once $ROOT . '/src/AstrX/Support/constants.php';
loadFunctions($ROOT . '/tools/module.php', 'function m_read_modules(', 'function m_build_pdo(');

echo "\n== module.php: flipping a flag actually flips it ==\n";

$TMP = sys_get_temp_dir() . '/astrx-modtest-' . bin2hex(random_bytes(6)) . '/';
mkdir($TMP, 0700, true);
register_shutdown_function(static function () use ($TMP): void {
    foreach (glob($TMP . '*') ?: [] as $f) { @unlink($f); }
    @rmdir($TMP);
});

/** Write a Modules.config.php whose 'chat' entry is spelled $spelling. */
function writeModulesFile(string $path, string $spelling): void
{
    file_put_contents($path, "<?php\ndeclare(strict_types=1);\nreturn [\n"
        . "    'Modules' => [\n"
        . "        'chat'       => {$spelling},\n"
        . "        'imageboard' => true,\n"
        . "    ],\n];\n");
}

// Every spelling that the old lowercase-only `(?:true|false)` pattern missed.
// A miss fell through to the "insert a new entry" branch, which PREPENDS the
// key — and PHP lets the LATER (original) entry win, so the tool reported a
// successful flip over a module that was still on.
foreach (['true', 'TRUE', 'True', '1', "'true'", '"on"'] as $spelling) {
    $file = $TMP . 'Modules.config.php';
    writeModulesFile($file, $spelling);

    $err = m_set_flag($file, 'chat', false);
    check("spelled {$spelling}: disable reports success", $err === '');

    $after = m_read_modules($file);
    eq("spelled {$spelling}: chat really reads back disabled", false, $after['chat'] ?? null);
    eq("spelled {$spelling}: imageboard is untouched", true, $after['imageboard'] ?? null);
    eq("spelled {$spelling}: exactly one 'chat' entry remains",
        1, substr_count((string) file_get_contents($file), "'chat'"));
}

// A module the file does not list yet gets inserted, once.
$file = $TMP . 'Modules.config.php';
writeModulesFile($file, 'true');
$err = m_set_flag($file, 'canary', false);
check('an unlisted module is inserted', $err === '');
eq('…and reads back disabled', false, m_read_modules($file)['canary'] ?? null);

// Setting a flag to the value it already holds is a SUCCESSFUL no-op. The old
// code compared the rewritten text to the original and called any non-difference
// "Could not update <file>"; m_cmd_toggle turns that into exit(1), so `disable
// chat` twice in a row killed CI's `set -euo pipefail` matrix with a message
// about a file-write problem, and the matrix could never be restarted, reordered
// or interrupted between a module's `disable` and its `enable`.
writeModulesFile($file, 'true');
check('disable reports success the first time',          m_set_flag($file, 'chat', false) === '');
check('disable is an idempotent no-op the second time',  m_set_flag($file, 'chat', false) === '');
eq('…and chat is still disabled', false, m_read_modules($file)['chat'] ?? null);
check('enable is symmetric: the first call succeeds',    m_set_flag($file, 'chat', true) === '');
check('enable is symmetric: the second is a no-op too',  m_set_flag($file, 'chat', true) === '');
eq('…and chat is still enabled', true, m_read_modules($file)['chat'] ?? null);
eq('the no-op left exactly one \'chat\' entry (nothing inserted)',
    1, substr_count((string) file_get_contents($file), "'chat'"));

// The genuine "could not update": no entry to rewrite AND no "'Modules' => ["
// to insert after. Nothing was written and nothing could be.
file_put_contents($file, "<?php\nreturn ['NotModules' => []];\n");
$err = m_set_flag($file, 'chat', false);
check('a file with no Modules block is still a hard failure',
    str_contains($err, 'Could not update'));

// A duplicate key later in the file overrides the one we edited: report it
// rather than printing DISABLED over a module that is still running.
file_put_contents($file, "<?php\nreturn [\n    'Modules' => [\n"
    . "        'chat' => true,\n        'chat' => true,\n    ],\n];\n");
$err = m_set_flag($file, 'chat', false);
check('a duplicate entry is reported as a failure, not a success', $err !== '');
check('…and the message names the cause', str_contains($err, 'duplicate'));

echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
