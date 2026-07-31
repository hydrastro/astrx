<?php
declare(strict_types=1);

/**
 * AstrX First-Run Setup Wizard — single-file, no JS, site-CSS styled.
 * DELETE THIS FILE after setup is complete.
 */

// The framework's php.ini sets session.sid_length=256 (or similar) to match
// SecureSessionHandler's 128-byte IDs. PHP's default file session handler
// would then try to write sess_<256 hex chars> — too long for ext4 (255 max).
// The wizard uses the file handler (not the DB-backed framework handler), so
// we override sid_length to a small sane value here.
ini_set('session.sid_length',          '32');
ini_set('session.sid_bits_per_character', '5');
ini_set('session.use_strict_mode',     '1');

// session_start() MUST come before any processing that writes $_SESSION.
session_start();

$configDir = __DIR__ . '/../resources/config/';
if (file_exists($configDir . '.setup_complete')) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><h1>404</h1></body></html>';
    exit;
}

// ── Fail-closed install guard + per-install setup token ─────────────────────────
// (a) The .setup_complete lock file above is NOT created by Docker's automatic
//     DB init, so it can be absent even on a fully-provisioned site. Fail closed
//     on REAL state: if the database already holds an administrator, the site is
//     installed — anonymous/tokenless callers get a 404 immediately. A caller
//     presenting the valid setup token (which proves server filesystem access)
//     may still proceed, so the wizard can finish step 4 after step 3 has created
//     the first admin, and an operator can legitimately re-enter setup.
// (b) Require a per-install setup token, stored OUTSIDE the docroot in the config
//     dir (0600, generated on first load), on every step that writes anything.
$setupToken     = setupToken($configDir);
$submittedToken = post('setup_token', '');
$tokenValid     = $submittedToken !== '' && hash_equals($setupToken, $submittedToken);

if (alreadyInstalled($configDir) && !$tokenValid) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><h1>404</h1></body></html>';
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post(string $k, string $d = ''): string { return is_string($_POST[$k] ?? null) ? trim((string)$_POST[$k]) : $d; }
function pb(string $k): bool { return !empty($_POST[$k]); }
function currentStep(): int { return max(1, min(5, (int)(($_GET['step'] ?? $_POST['_step'] ?? 1)))); }

// ── Setup token (fail-closed installer) ─────────────────────────────────────────
// Read-or-create the per-install setup token. Lives OUTSIDE the docroot in the
// config dir so it is not web-readable; the operator must read it from the server
// filesystem and paste it into the form. Generated 0600 on first load.
function setupToken(string $configDir): string
{
    $path = $configDir . '.setup_token';
    $existing = @file_get_contents($path);
    if (is_string($existing) && trim($existing) !== '') {
        return trim($existing);
    }
    $token = bin2hex(random_bytes(32));
    @file_put_contents($path, $token, LOCK_EX);
    @chmod($path, 0600);
    return $token;
}

// Fail-closed install detection: true only if we can connect to the configured
// database AND it already contains an admin (type = 1, not deleted). If the DB
// is unreachable or the schema is absent we return false and let the token gate
// protect the (still-uninstalled) site.
function alreadyInstalled(string $configDir): bool
{
    $cfgPath = $configDir . 'PDO.config.php';
    if (!is_file($cfgPath)) { return false; }
    /** @var mixed $cfg */
    $cfg = require $cfgPath;
    if (!is_array($cfg) || !isset($cfg['PDO']) || !is_array($cfg['PDO'])) { return false; }
    /** @var array<string,mixed> $db */
    $db = $cfg['PDO'];
    $conn = tryConn(
        (string)($db['db_host']     ?? 'localhost'),
        (string)($db['db_name']     ?? ''),
        (string)($db['db_username'] ?? ''),
        (string)($db['db_password'] ?? ''),
        (int)($db['db_port']        ?? 3306),
    );
    if (is_string($conn)) { return false; }
    try {
        $stmt = $conn->query('SELECT 1 FROM `user` WHERE `type` = 1 AND `deleted` = 0 LIMIT 1');
        if ($stmt === false) { return false; }
        $found = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $found !== false;
    } catch (\PDOException) {
        return false;
    }
}

// Renders the setup-token input, carried on every write step's form. Pre-filled
// with the SUBMITTED value only (never the real token — that must be read from
// the server file), so loading the page never leaks the token.
function tokenFieldHtml(string $submitted): string
{
    return '<p><label>Setup token<br>'
         . '<input type="text" class="input" name="setup_token" autocomplete="off" '
         . 'style="width:100%" value="' . e($submitted) . '"></label>'
         . '<br><small>Paste the contents of <code>resources/config/.setup_token</code> '
         . '(readable on the server only).</small></p>';
}

// ── Requirements ──────────────────────────────────────────────────────────────
function checkReqs(): array
{
    $c = [];
    $c[] = ['PHP &ge; 8.4',       PHP_VERSION_ID >= 80400,      PHP_VERSION];
    $c[] = ['pdo',                 extension_loaded('pdo'),       'required'];
    $c[] = ['pdo_mysql',           extension_loaded('pdo_mysql'), 'required'];
    $c[] = ['openssl',             extension_loaded('openssl'),   'required'];
    $c[] = ['gd',                  extension_loaded('gd'),        'required'];
    $c[] = ['mbstring',            extension_loaded('mbstring'),  'required'];
    foreach ([
        __DIR__ . '/../resources/config/'         => 'resources/config/',
        __DIR__ . '/../resources/template/cache/' => 'resources/template/cache/',
        __DIR__ . '/../resources/avatar/'         => 'resources/avatar/',
    ] as $dir => $label) {
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $c[] = ["$label writable", is_writable($dir), $dir];
    }
    // Fix 10.5: PHP session save path must be writable for the wizard to
    // persist state between steps. Failure here means step 2 → step 3 loses
    // the DB credentials silently in some container environments.
    $sessionPath = session_save_path() ?: sys_get_temp_dir();
    $c[] = ['session save path writable', is_writable($sessionPath), $sessionPath];
    return $c;
}
function allOk(array $c): bool { foreach ($c as [,$ok]) { if (!$ok) return false; } return true; }

// ── Config writers ────────────────────────────────────────────────────────────
function writePDO(string $h, string $d, string $u, string $p, int $port): string
{
    $path = __DIR__ . '/../resources/config/PDO.config.php';
    [$h2,$d2,$u2,$p2] = array_map('addslashes', [$h,$d,$u,$p]);
    $content = "<?php\ndeclare(strict_types=1);\nreturn [\n    'PDO' => [\n        'db_type'             => 'mysql',\n        'db_host'             => '$h2',\n        'db_name'             => '$d2',\n        'db_port'             => $port,\n        'db_username'         => '$u2',\n        'db_password'         => '$p2',\n        'emulate_prepares'    => false,\n        'errmode_exception'   => true,\n        'default_fetch_assoc' => true,\n    ],\n];\n";
    // Fix: check the return value so permission errors surface as flash messages
    // instead of silently falling through to step 3 with no config written.
    $bytes = @file_put_contents($path, $content);
    if ($bytes === false) {
        return "Cannot write {$path}. Check directory permissions: this directory must be writable by the web server user.";
    }
    return '';
}

function writeSecurity(string $secret, string $env): string
{
    $s = addslashes($secret);
    $envConst = match($env) { 'production' => 'PRODUCTION', 'staging' => 'STAGING', default => 'DEVELOPMENT' };
    foreach ([
        __DIR__ . '/../resources/config/Session.config.php' => [
            "/'server_secret'\s*=>\s*'[^']*'/" => "'server_secret' => '$s'",
        ],
        __DIR__ . '/../resources/config/config.php' => [
            "/'environment'\s*=>\s*EnvironmentType::[A-Z]+->value/" => "'environment' => EnvironmentType::{$envConst}->value",
        ],
    ] as $path => $replacements) {
        $content = @file_get_contents($path);
        if ($content === false) {
            return "Cannot read {$path}. Check that the file exists and is readable.";
        }
        foreach ($replacements as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content) ?? $content;
        }
        // Write atomically via a temp file + rename so this succeeds even when
        // the existing config file itself is read-only — a common cause of a
        // step-4-only failure (files shipped 0644 owned by a different user than
        // the web server). rename() only needs the CONFIG DIRECTORY to be
        // writable, which the step-1 requirements check already verifies.
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            return "Cannot write {$path}. Make sure the resources/config/ directory is "
                 . "writable by the web-server user (chown/chmod so PHP can write into it).";
        }
    }
    return '';
}

// ── DB helpers ────────────────────────────────────────────────────────────────
function tryConn(string $h, string $d, string $u, string $p, int $port): PDO|string
{
    try {
        return new PDO(
            "mysql:host=$h;port=$port;dbname=$d;charset=utf8mb4",
            $u,
            $p,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
                // MySQL leaves SELECT-bearing migration statements open unless
                // result buffering is enabled. Without this, a later statement
                // can fail with "Cannot execute queries while other unbuffered
                // queries are active" even though the reported SQL is innocent.
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        );
    } catch (\PDOException $e) { return $e->getMessage(); }
}
function sessionConn(): PDO|string
{
    // Read credentials from the config file that step 2 already wrote.
    // Avoids any session-persistence dependency between requests.
    $cfgPath = __DIR__ . '/../resources/config/PDO.config.php';
    if (!file_exists($cfgPath)) {
        return 'Database config not found. Please go back to step 2.';
    }
    $cfg = require $cfgPath;
    if (!is_array($cfg) || !isset($cfg['PDO']) || !is_array($cfg['PDO'])) {
        return 'Invalid database config. Please go back to step 2.';
    }
    $db = $cfg['PDO'];
    return tryConn(
        (string)($db['db_host']     ?? 'localhost'),
        (string)($db['db_name']     ?? ''),
        (string)($db['db_username'] ?? ''),
        (string)($db['db_password'] ?? ''),
        (int)($db['db_port']        ?? 3306)
    );
}
function runSQL(PDO $pdo, string $file): string
{
    // Fix: surface missing files instead of silently returning success.
    if (!file_exists($file)) {
        return "Schema file not found: $file";
    }

    $sql = (string)file_get_contents($file);
    $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;
    $stmts = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');

    foreach ($stmts as $stmt) {
        try {
            // query() gives us a cursor we can always close. That makes the
            // migration runner safe for SELECT, SHOW, CREATE VIEW ... SELECT,
            // INSERT ... SELECT, and other SELECT-bearing statements.
            $cursor = $pdo->query($stmt);
            if ($cursor !== false) {
                if ($cursor->columnCount() > 0) {
                    $cursor->fetchAll(PDO::FETCH_ASSOC);
                }
                $cursor->closeCursor();
            }
        } catch (\PDOException $e) {
            if (!in_array((string)$e->getCode(), ['42S01','42S21','23000'], true)) {
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        return '';
    } catch (\PDOException $e) {
        return $e->getMessage();
    }
}

function runMigration(PDO $pdo, string $file): string
{
    $err = ensureMigrationTable($pdo);
    if ($err !== '') {
        return 'Could not initialise migration table: ' . $err;
    }
    if (!file_exists($file)) {
        return "Migration file not found: $file";
    }

    $name = basename($file);
    $checksum = hash_file('sha256', $file);
    if (!is_string($checksum) || $checksum === '') {
        return "Could not checksum migration file: $file";
    }

    try {
        $check = $pdo->prepare('SELECT checksum FROM `migration` WHERE file_name = :file_name LIMIT 1');
        $check->execute([':file_name' => $name]);
        $existing = $check->fetchColumn();
        $check->closeCursor();
        if (is_string($existing) && $existing !== '') {
            if (hash_equals($existing, $checksum)) {
                return '';
            }
            return "Migration {$name} was already executed with a different checksum. Create a new migration instead of editing an applied one.";
        }
    } catch (\PDOException $e) {
        return $e->getMessage();
    }

    $err = runSQL($pdo, $file);
    if ($err !== '') {
        return $err;
    }

    try {
        $record = $pdo->prepare('INSERT INTO `migration` (file_name, checksum) VALUES (:file_name, :checksum)');
        $record->execute([':file_name' => $name, ':checksum' => $checksum]);
        return '';
    } catch (\PDOException $e) {
        return $e->getMessage();
    }
}

/**
 * Find a setup SQL file in any of the conventional locations.
 * Docker layouts vary — some mount the whole repo as /app, others mount
 * only public/, src/, and resources/. We try every plausible location so
 * the wizard works regardless of how the container is configured.
 *
 * Returns the first existing path, or null if nothing matched.
 */
function findSetupFile(string $name): ?string
{
    $candidates = [
        __DIR__ . '/../src/setup/' . $name,   // canonical: ships inside src/ (always mounted)
        __DIR__ . '/../setup/' . $name,       // alternative: repo-root setup/ (only works when whole repo is mounted)
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) { return $c; }
    }
    return null;
}

function listSetupMigrations(): array
{
    $found = [];
    foreach ([__DIR__ . '/../src/setup/', __DIR__ . '/../setup/'] as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir . 'migrate_*.sql') ?: [] as $m) {
            $found[basename($m)] = $m;   // de-dup by filename, prefer first found
        }
    }
    return array_values($found);
}
function removeSeedAdmin(PDO $pdo): string
{
    // Older setup SQL seeded a public default Administrator account. The wizard
    // now creates the real first admin from the submitted setup form, so remove
    // only that exact legacy seed account before inserting the chosen admin.
    $legacyHash = '$argon2id$v=19$m=65536,t=4,p=1$b2Z2cnVLM0pSMy9xUVVicw$6KUaczD3Y6rGl28q61y6YXxriNmGqKv2I6xucl8rcSE';
    try {
        $stmt = $pdo->prepare('DELETE FROM `user` WHERE username = :u AND password = :p AND type = 1 AND verified = 1 AND deleted = 0');
        $stmt->execute([':u' => 'Administrator', ':p' => $legacyHash]);
        return '';
    } catch (\PDOException $e) { return $e->getMessage(); }
}

function makeAdmin(PDO $pdo, string $user, string $pass, string $mbox): string
{
    try {
        $stmt = $pdo->prepare('INSERT INTO `user` (id,username,mailbox,password,type,verified,deleted) VALUES (UNHEX(:id),:u,:m,:p,1,1,0)');
        $stmt->execute([':id'=>bin2hex(random_bytes(16)),':u'=>$user,':m'=>$mbox,':p'=>password_hash($pass,PASSWORD_ARGON2ID)]);
        return '';
    } catch (\PDOException $e) { return $e->getMessage(); }
}

// ── Processing ────────────────────────────────────────────────────────────────
$step = currentStep();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$tokenValid) {
    // No write happens without a valid setup token.
    $errors[] = 'Invalid or missing setup token. Open resources/config/.setup_token '
              . 'on the server and paste its contents into the "Setup token" field below.';
    $step = currentStep();
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ps = (int)post('_step','1');

    if ($ps === 1) {
        $step = allOk(checkReqs()) ? 2 : 1;
        if ($step === 1) $errors[] = 'Please resolve failing checks before continuing.';
    }

    elseif ($ps === 2) {
        $h=post('db_host','localhost'); $d=post('db_name','content_manager');
        $u=post('db_user','root');      $p=post('db_pass','');
        $port=(int)post('db_port','3306');
        $conn = tryConn($h,$d,$u,$p,$port);
        if (is_string($conn)) { $errors[]='Database connection failed: '.$conn; }
        else {
            $writeErr = writePDO($h,$d,$u,$p,$port);
            if ($writeErr !== '') {
                $errors[] = $writeErr;
            }

            if ($errors === [] && pb('run_migrations')) {
                $tablesPath = findSetupFile('tables.sql');
                if ($tablesPath === null) {
                    $errors[] = 'Schema file tables.sql not found in either src/setup/ or setup/.';
                } else {
                    $err = runSQL($conn, $tablesPath);
                    if ($err !== '') {
                        $errors[] = 'SQL error: ' . $err;
                    }
                }
                // Auto-apply every migrate_*.sql found in either location.
                // Each successful migration is recorded by filename + checksum
                // so setup can be safely re-entered without blindly replaying
                // the same SQL against an already-upgraded database.
                if ($errors === []) {
                    $err = ensureMigrationTable($conn);
                    if ($err !== '') {
                        $errors[] = 'Could not initialise migration table: ' . $err;
                    }
                }
                if ($errors === []) {
                    foreach (listSetupMigrations() as $mf) {
                        $err = runMigration($conn, $mf);
                        if ($err !== '') {
                            $errors[] = 'SQL error in ' . basename($mf) . ': ' . $err;
                            break;
                        }
                    }
                }
            }

            if ($errors===[]) {
                $step = 3;
            }
        }
    }

    elseif ($ps === 3) {
        $au=post('admin_user'); $ap=post('admin_pass'); $ap2=post('admin_pass2'); $am=post('admin_mailbox');
        if ($au===''           ) $errors[]='Username is required.';
        if (strlen($ap)<8      ) $errors[]='Password must be at least 8 characters.';
        if ($ap!==$ap2         ) $errors[]='Passwords do not match.';
        if ($errors===[]) {
            $conn = sessionConn();
            if (is_string($conn)) { $errors[]=$conn; }
            else {
                $err = removeSeedAdmin($conn);
                if ($err !== '') {
                    $errors[] = 'Could not remove legacy seeded admin: ' . $err;
                } else {
                    $err = makeAdmin($conn,$au,$ap,$am?:$au);
                    if ($err!=='') $errors[]='Could not create admin: '.$err;
                    else $step=4;
                }
            }
        }
    }

    elseif ($ps === 4) {
        $secret = post('server_secret','');
        if ($secret==='') $secret = bin2hex(random_bytes(32));
        $writeErr = writeSecurity($secret, post('environment','production'));
        if ($writeErr !== '') {
            $errors[] = $writeErr;
        } else {
            $lockBytes = @file_put_contents($configDir.'.setup_complete', date('c'));
            if ($lockBytes === false) {
                $errors[] = "Cannot write lock file at {$configDir}.setup_complete";
            } else {
                // Setup done — the one-time token is no longer needed.
                @unlink($configDir . '.setup_token');
                $step = 5;
            }
        }
    }
}

$checks     = checkReqs();
$autoSecret = bin2hex(random_bytes(32));
$stepLabels = [1=>'1. Requirements',2=>'2. Database',3=>'3. Admin',4=>'4. Security',5=>'5. Done'];
$siteCSS    = (string)(@file_get_contents(__DIR__.'/../resources/template/style.css') ?: '');

?><!DOCTYPE html>
<html lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1;">
<title>AstrX Setup — Step <?= $step ?> of 5</title>
<style><?= $siteCSS ?></style>
</head>
<body>
<div id="wrap">

  <div id="header">
    <h1 id="title"><a href="setup.php">AstrX Setup</a></h1>
  </div>

  <div id="top_nav">
    <ul id="nav" class="nav">
<?php foreach ($stepLabels as $n => $label): ?>
      <li><a href="setup.php?step=<?= $n ?>"<?= $n===$step?' class="active"':'' ?>><?= e($label) ?></a></li>
<?php endforeach ?>
    </ul>
  </div>

<?php if ($errors !== []): ?>
  <div id="message_bar">
<?php foreach ($errors as $err): ?>
    <p class="flash-error">&#9888; <?= e($err) ?></p>
<?php endforeach ?>
  </div>
<?php endif ?>

  <div id="main">

<?php if ($step < 5): ?>
    <p>&#128273; This installer is protected by a one-time <strong>setup token</strong>.
    Open <code>resources/config/.setup_token</code> on the server and paste its contents
    into the Setup token field on each step below.</p>
<?php endif ?>

<?php if ($step === 1): /* ── Requirements ── */ ?>
<h2>Step 1 — Requirements</h2>
<table>
  <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
  <tbody>
<?php foreach ($checks as [$label, $ok, $detail]): ?>
  <tr>
    <td><?= $label ?></td>
    <td><?= $ok ? '<span style="color:#0f0">&#10003; OK</span>' : '<span style="color:#f44">&#10007; Fail</span>' ?></td>
    <td><?= e((string)$detail) ?></td>
  </tr>
<?php endforeach ?>
  </tbody>
</table>
<?php if (!allOk($checks)): ?><p>Resolve the failing checks, then re-check.</p><?php endif ?>
<form method="POST">
  <input type="hidden" name="_step" value="1">
  <?= tokenFieldHtml($submittedToken) ?>
  <input type="submit" class="input" value="<?= allOk($checks) ? 'Continue &rarr;' : 'Re-check' ?>">
</form>

<?php elseif ($step === 2): /* ── Database ── */ ?>
<h2>Step 2 — Database connection</h2>
<form method="POST">
  <input type="hidden" name="_step" value="2">
  <?= tokenFieldHtml($submittedToken) ?>
  <table>
    <tbody>
      <tr><td>Host</td>          <td><input type="text"     class="input" name="db_host" value="<?= e(post('db_host','localhost')) ?>"></td></tr>
      <tr><td>Database name</td> <td><input type="text"     class="input" name="db_name" value="<?= e(post('db_name','content_manager')) ?>"></td></tr>
      <tr><td>Port</td>          <td><input type="text"     class="input" name="db_port" value="<?= e(post('db_port','3306')) ?>"></td></tr>
      <tr><td>Username</td>      <td><input type="text"     class="input" name="db_user" value="<?= e(post('db_user','root')) ?>"></td></tr>
      <tr><td>Password</td>      <td><input type="password" class="input" name="db_pass"></td></tr>
      <tr><td colspan="2"><label><input type="checkbox" name="run_migrations" value="1" checked> Run SQL setup (tables.sql + migrate.sql)</label><br><small>Uncheck if you have already initialised the database manually.</small></td></tr>
    </tbody>
  </table>
  <input type="submit" class="input" value="Connect &amp; continue &rarr;">
</form>

<?php elseif ($step === 3): /* ── Admin account ── */ ?>
<h2>Step 3 — Admin account</h2>
<p>Creates the first administrator. More users can be added via the admin panel.</p>
<form method="POST">
  <input type="hidden" name="_step" value="3">
  <?= tokenFieldHtml($submittedToken) ?>
  <table>
    <tbody>
      <tr><td>Username</td>     <td><input type="text"     class="input" name="admin_user"    value="<?= e(post('admin_user','admin')) ?>"></td></tr>
      <tr>
        <td>Mailbox</td>
        <td>
          <input type="text" class="input" name="admin_mailbox" value="<?= e(post('admin_mailbox','')) ?>" placeholder="Leave blank to use username"><br>
          <small>IMAP local-part only (e.g. <code>admin</code> without @domain).</small>
        </td>
      </tr>
      <tr><td>Password</td>        <td><input type="password" class="input" name="admin_pass"></td></tr>
      <tr><td>Repeat password</td> <td><input type="password" class="input" name="admin_pass2"></td></tr>
    </tbody>
  </table>
  <input type="submit" class="input" value="Create admin &amp; continue &rarr;">
</form>

<?php elseif ($step === 4): /* ── Security ── */ ?>
<h2>Step 4 — Security &amp; environment</h2>
<form method="POST">
  <input type="hidden" name="_step" value="4">
  <?= tokenFieldHtml($submittedToken) ?>
  <table>
    <tbody>
      <tr>
        <td>Server secret</td>
        <td>
          <input type="text" class="input" name="server_secret" value="<?= e($autoSecret) ?>"><br>
          <small>A random hex string used to derive session encryption keys. Keep it secret and constant — changing it logs everyone out.</small>
        </td>
      </tr>
      <tr>
        <td>Environment</td>
        <td>
          <select class="input" name="environment">
            <option value="production">Production — errors hidden, assertions off</option>
            <option value="staging">Staging — errors logged, not displayed</option>
            <option value="development">Development — errors displayed, Xdebug if loaded</option>
          </select>
        </td>
      </tr>
    </tbody>
  </table>
  <input type="submit" class="input" value="Save &amp; finish &rarr;">
</form>

<?php elseif ($step === 5): /* ── Done ── */ ?>
<h2>Setup complete!</h2>
<p>AstrX is configured and ready to use.</p>
<hr>
<p><strong>Security:</strong> delete <code>public/setup.php</code> now.</p>
<pre style="color:#fff">rm public/setup.php</pre>
<p>A lock file was written to <code>resources/config/.setup_complete</code> so
revisiting this URL returns 404, but removing the file is cleaner.</p>
<p><a href="/">&rarr; Go to site</a></p>
<?php endif ?>

    <p id="go_top"><span class="right"><a href="#">Go top</a></span></p>
  </div>

  <div id="footer">
    <p class="left">AstrX First-Run Setup</p>
    <p class="right">Step <?= $step ?> of 5</p>
    <div class="clear"></div>
  </div>

</div>
</body>
</html>
