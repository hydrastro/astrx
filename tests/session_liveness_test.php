<?php
declare(strict_types=1);

/**
 * Standalone SecureSessionHandler test — NO AstrX bootstrap, SQLite in memory.
 *
 * Covers the "gc() un-rotates a rotated session ID" defect and the two smaller
 * ones in the same file:
 *
 *   1. gc() DELETES rotated-away rows past the grace window instead of nulling
 *      replace_at. The old UPDATE erased the only evidence read() used, and the
 *      old row — which session_regenerate_id(false) had filled with the
 *      AUTHENTICATED snapshot — became an ordinary live session again.
 *   2. validateId(), read() and gc() agree, because they share rowIsLive().
 *   3. read()'s destroy() sticks: the end-of-request write() no longer
 *      re-INSERTs the row it just deleted.
 *   4. ServerSecret: perms are tight from creation, the value is stable across
 *      instances, and an unusable location fails loudly instead of degrading to
 *      a per-request random key.
 *
 * SQLite covers every statement this test exercises. write()'s UPSERT is
 * MySQL-specific, which test 3 turns to its advantage: a write that reaches the
 * database throws on SQLite, so "no exception AND no row" proves the guard
 * short-circuited before the query.
 *
 * Run:  php tests/session_liveness_test.php
 */

namespace AstrX\Config {
    if (!\class_exists(InjectConfig::class)) {
        #[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
        final class InjectConfig
        {
            public function __construct(public readonly string $key) {}
        }
    }
}

namespace AstrX\Support {
    // SecureSessionHandler/ServerSecret call configDir(); point it at a scratch
    // dir so the test never touches a real install's secret.
    if (!\function_exists('AstrX\Support\configDir')) {
        function configDir(): string
        {
            return \rtrim((string) \getenv('ASTRX_TEST_CONFIG_DIR'), '/') . '/';
        }
    }
}

namespace {

    use AstrX\Session\SecureSessionHandler;
    use AstrX\Session\ServerSecret;

    $CLASS_DIR = dirname(__DIR__) . '/src/AstrX/';
    spl_autoload_register(static function (string $class) use ($CLASS_DIR): void {
        if (strncmp($class, 'AstrX\\', 6) !== 0) { return; }
        $file = $CLASS_DIR . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) { require_once $file; }
    });

    $scratch = sys_get_temp_dir() . '/astrx_session_test_' . bin2hex(random_bytes(6));
    mkdir($scratch, 0700, true);
    putenv('ASTRX_TEST_CONFIG_DIR=' . $scratch);
    register_shutdown_function(static function () use ($scratch): void {
        foreach ((array) glob($scratch . '/{,.}*', GLOB_BRACE) as $f) {
            if (is_string($f) && is_file($f)) { @unlink($f); }
        }
        @rmdir($scratch);
    });

    $PASS = 0;
    $FAIL = 0;
    function check(string $label, bool $cond): void
    {
        global $PASS, $FAIL;
        if ($cond) { $PASS++; echo "  ok   - $label\n"; }
        else       { $FAIL++; echo "  FAIL - $label\n"; }
    }

    function freshPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE session (
                id          TEXT PRIMARY KEY,
                timestamp   INTEGER NOT NULL,
                data        BLOB NOT NULL DEFAULT "",
                replaced_by TEXT NULL DEFAULT NULL,
                replace_at  INTEGER NULL DEFAULT NULL
            )'
        );
        return $pdo;
    }

    function seedRow(PDO $pdo, string $rawId, int $timestamp, ?int $replaceAt, string $data = 'x'): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO session (id, timestamp, data, replaced_by, replace_at)
             VALUES (:id, :ts, :data, :rb, :ra)'
        );
        $stmt->execute([
            ':id'   => hash('sha512', $rawId),
            ':ts'   => $timestamp,
            ':data' => $data,
            ':rb'   => $replaceAt === null ? null : hash('sha512', 'successor'),
            ':ra'   => $replaceAt,
        ]);
    }

    function rowExists(PDO $pdo, string $rawId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM session WHERE id = :id');
        $stmt->execute([':id' => hash('sha512', $rawId)]);
        return $stmt->fetch() !== false;
    }

    function handler(PDO $pdo, int $grace = 30): SecureSessionHandler
    {
        $h = new SecureSessionHandler($pdo);
        $h->setEncrypt(false);            // plaintext: this test is about liveness
        $h->setGraceSeconds($grace);
        $h->setServerSecret(str_repeat('a', 64));
        return $h;
    }

    echo "gc() vs a rotated session row\n";

    // ── 1. The defect: gc() used to un-rotate the old row ────────────────────
    $pdo = freshPdo();
    $h   = handler($pdo);
    $now = time();

    // A row rotated away 1000 s ago, still "in use" (timestamp refreshed on
    // every request), holding an authenticated snapshot.
    seedRow($pdo, 'old-sid', timestamp: $now, replaceAt: $now - 1000, data: 'logged_in|b:1;');

    $h->gc(1440);
    check(
        'gc() DELETES a rotated-away row past the grace window',
        !rowExists($pdo, 'old-sid'),
    );

    // The regression this replaces: after the old UPDATE, read() served the row.
    $pdo = freshPdo();
    $h   = handler($pdo);
    seedRow($pdo, 'old-sid', timestamp: $now, replaceAt: $now - 1000, data: 'logged_in|b:1;');
    // Simulate what the OLD gc() did — clear the pointer, keep the row.
    $pdo->exec('UPDATE session SET replaced_by = NULL, replace_at = NULL');
    check(
        'REGRESSION SHAPE: with replace_at cleared the row IS served '
        . '(this is what the old gc() produced)',
        $h->read('old-sid') === 'logged_in|b:1;',
    );

    // ── 2. One rule, three call sites ───────────────────────────────────────
    echo "\nvalidateId() / read() / gc() agree\n";

    $pdo = freshPdo();
    $h   = handler($pdo, grace: 30);
    seedRow($pdo, 'expired', timestamp: $now, replaceAt: $now - 1000, data: 'logged_in|b:1;');
    seedRow($pdo, 'in-grace', timestamp: $now, replaceAt: $now - 5,   data: 'logged_in|b:1;');
    seedRow($pdo, 'never-rotated', timestamp: $now, replaceAt: null,  data: 'plain');

    check('validateId() REJECTS a rotated row past grace',   !$h->validateId('expired'));
    check('validateId() accepts a rotated row inside grace',  $h->validateId('in-grace'));
    check('validateId() accepts a never-rotated row',         $h->validateId('never-rotated'));
    check('validateId() rejects an unknown id',              !$h->validateId('no-such-sid'));

    $pdo2 = freshPdo();
    $h2   = handler($pdo2, grace: 30);
    seedRow($pdo2, 'expired', timestamp: $now, replaceAt: $now - 1000, data: 'logged_in|b:1;');
    check('read() returns nothing for a rotated row past grace', $h2->read('expired') === '');
    check('read() destroys that row on the way out',             !rowExists($pdo2, 'expired'));

    $pdo3 = freshPdo();
    $h3   = handler($pdo3, grace: 30);
    seedRow($pdo3, 'in-grace', timestamp: $now, replaceAt: $now - 5, data: 'still-valid');
    check('read() still serves a rotated row INSIDE grace', $h3->read('in-grace') === 'still-valid');

    $pdo4 = freshPdo();
    $h4   = handler($pdo4, grace: 30);
    seedRow($pdo4, 'in-grace', timestamp: $now, replaceAt: $now - 5, data: 'still-valid');
    $h4->gc(1440);
    check('gc() leaves a rotated row inside grace alone', rowExists($pdo4, 'in-grace'));

    // Emulated prepares hand INT columns back as strings; the rule must survive.
    $pdo5 = freshPdo();
    $h5   = handler($pdo5, grace: 30);
    $stmt = $pdo5->prepare(
        'INSERT INTO session (id, timestamp, data, replaced_by, replace_at)
         VALUES (:id, :ts, :data, NULL, :ra)'
    );
    $stmt->execute([
        ':id'   => hash('sha512', 'stringy'),
        ':ts'   => (string) $now,
        ':data' => 'logged_in|b:1;',
        ':ra'   => (string) ($now - 1000),   // replace_at as a STRING
    ]);
    check(
        'the grace rule still fires when replace_at comes back as a string',
        !$h5->validateId('stringy'),
    );

    // ── 3. destroy() sticks ─────────────────────────────────────────────────
    echo "\nread()'s destroy() is final\n";

    $pdo6 = freshPdo();
    $h6   = handler($pdo6, grace: 30);
    seedRow($pdo6, 'expired', timestamp: $now, replaceAt: $now - 1000, data: 'logged_in|b:1;');
    $h6->read('expired');                       // destroys it

    $threw = false;
    try {
        // PHP calls this at the end of every request, with the same id.
        $h6->write('expired', 'logged_in|b:1;');
    } catch (\PDOException) {
        $threw = true;                          // means the query actually ran
    }
    check('write() after destroy() does not reach the database', !$threw);
    check('write() after destroy() does NOT resurrect the row',  !rowExists($pdo6, 'expired'));
    check('validateId() keeps rejecting the destroyed id',       !$h6->validateId('expired'));

    // Control: an id that was NOT destroyed does reach the (MySQL-only) UPSERT,
    // which proves the assertions above are testing the guard and not SQLite.
    $reached = false;
    try {
        $h6->write('some-other-sid', 'data');
    } catch (\PDOException) {
        $reached = true;
    }
    check('control: an ordinary write DOES reach the database', $reached);

    // ── 4. ServerSecret ─────────────────────────────────────────────────────
    echo "\nServerSecret\n";

    $secretFile = $scratch . '/.server_secret_generated';
    $s1 = new ServerSecret();
    $first = $s1->bytes();
    check('generates a 32-byte secret on first run', strlen($first) === 32);
    check('persists it to the config dir',           is_file($secretFile));
    check('the file is 0600 from creation',          (fileperms($secretFile) & 0777) === 0600);
    check('the same instance returns the same bytes', $s1->bytes() === $first);

    $s2 = new ServerSecret();
    check('a second instance reads the SAME persisted secret (no per-request re-roll)',
        $s2->bytes() === $first);

    $s3 = new ServerSecret();
    $s3->setConfigured(str_repeat('c', 64));
    check('an explicitly configured secret wins', $s3->bytes() === str_repeat('c', 64));

    $s4 = new ServerSecret();
    $s4->setConfigured(ServerSecret::LEAKED_SERVER_SECRET);
    check('the once-committed secret is ignored, not used',
        $s4->bytes() !== ServerSecret::LEAKED_SERVER_SECRET);

    // Nothing readable, nothing creatable → loud failure, NOT a random key.
    //
    // The config-dir candidate is made unusable by pointing configDir() at a
    // directory that does not exist (fopen('xb') then fails for root too — a
    // permission-based setup would not, and this suite may run as root). The
    // temp-dir candidate is occupied by a group/world-accessible file, which is
    // exactly the local-user pre-seeding ServerSecret must refuse to trust.
    $tempCandidate = sys_get_temp_dir() . '/astrx_server_secret';
    $stashed       = null;
    if (file_exists($tempCandidate)) {
        $stashed = $tempCandidate . '.' . bin2hex(random_bytes(4)) . '.testbak';
        if (!@rename($tempCandidate, $stashed)) { $stashed = null; }
    }

    if (file_exists($tempCandidate)) {
        echo "  skip - temp-dir candidate in use by another process\n";
    } else {
        touch($tempCandidate);
        chmod($tempCandidate, 0666);            // group/world accessible ⇒ untrusted
        putenv('ASTRX_TEST_CONFIG_DIR=' . $scratch . '/does-not-exist');

        $failedLoudly = false;
        try {
            (new ServerSecret())->bytes();
        } catch (\RuntimeException) {
            $failedLoudly = true;
        }
        check('no usable location → RuntimeException, not a per-request random key', $failedLoudly);

        putenv('ASTRX_TEST_CONFIG_DIR=' . $scratch);
        @unlink($tempCandidate);
    }
    if ($stashed !== null) { @rename($stashed, $tempCandidate); }

    echo "\n{$PASS} passed, {$FAIL} failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
