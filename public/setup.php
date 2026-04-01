<?php
declare(strict_types=1);
/**
 * AstrX First-Run Setup Wizard — single-file, no JS, site-CSS styled.
 * DELETE THIS FILE after setup is complete.
 */

// session_start() MUST come before any processing that writes $_SESSION.
session_start();

$configDir = __DIR__ . '/../resources/config/';
if (file_exists($configDir . '.setup_complete')) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><body><h1>404</h1></body></html>';
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function post(string $k, string $d = ''): string { return is_string($_POST[$k] ?? null) ? trim((string)$_POST[$k]) : $d; }
function pb(string $k): bool { return !empty($_POST[$k]); }
function currentStep(): int { return max(1, min(5, (int)(($_GET['step'] ?? $_POST['_step'] ?? 1)))); }

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
    return $c;
}
function allOk(array $c): bool { foreach ($c as [,$ok]) { if (!$ok) return false; } return true; }

// ── Config writers ────────────────────────────────────────────────────────────
function writePDO(string $h, string $d, string $u, string $p, int $port): void
{
    [$h,$d,$u,$p] = array_map('addslashes', [$h,$d,$u,$p]);
    file_put_contents(__DIR__ . '/../resources/config/PDO.config.php', "<?php\ndeclare(strict_types=1);\nreturn [\n    'PDO' => [\n        'db_type'             => 'mysql',\n        'db_host'             => '$h',\n        'db_name'             => '$d',\n        'db_port'             => $port,\n        'db_username'         => '$u',\n        'db_password'         => '$p',\n        'emulate_prepares'    => false,\n        'errmode_exception'   => true,\n        'default_fetch_assoc' => true,\n    ],\n];\n");
}

function writeSecurity(string $secret, string $env): void
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
        $content = @file_get_contents($path) ?: '';
        foreach ($replacements as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content) ?? $content;
        }
        file_put_contents($path, $content);
    }
}

// ── DB helpers ────────────────────────────────────────────────────────────────
function tryConn(string $h, string $d, string $u, string $p, int $port): PDO|string
{
    try {
        return new PDO("mysql:host=$h;port=$port;dbname=$d;charset=utf8mb4", $u, $p,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
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
    if (!file_exists($file)) return '';
    $stmts = array_filter(array_map('trim', explode(';', preg_replace('/--[^\n]*/','',(string)file_get_contents($file))??'')), fn($s)=>$s!=='');
    foreach ($stmts as $stmt) {
        try { $pdo->exec($stmt); }
        catch (\PDOException $e) { if (!in_array((string)$e->getCode(),['42S01','42S21','23000','42000'],true)) return $e->getMessage().' | '.substr($stmt,0,200); }
    }
    return '';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            if (pb('run_migrations')) {
                $err = runSQL($conn, __DIR__.'/../setup/tables.sql');
                if ($err==='' ) $err = runSQL($conn, __DIR__.'/../setup/migrate.sql');
                if ($err!=='' ) $errors[]='SQL error: '.$err;
            }
            if ($errors===[]) {
                writePDO($h,$d,$u,$p,$port);
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
                $err = makeAdmin($conn,$au,$ap,$am?:$au);
                if ($err!=='') $errors[]='Could not create admin: '.$err;
                else $step=4;
            }
        }
    }

    elseif ($ps === 4) {
        $secret = post('server_secret','');
        if ($secret==='') $secret = bin2hex(random_bytes(32));
        writeSecurity($secret, post('environment','production'));
        file_put_contents($configDir.'.setup_complete', date('c'));
        $step=5;
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
  <input type="submit" class="input" value="<?= allOk($checks) ? 'Continue &rarr;' : 'Re-check' ?>">
</form>

<?php elseif ($step === 2): /* ── Database ── */ ?>
<h2>Step 2 — Database connection</h2>
<form method="POST">
  <input type="hidden" name="_step" value="2">
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
