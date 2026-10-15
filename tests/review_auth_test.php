<?php
declare(strict_types=1);

// Real service/repository regression; HTTP deployment is covered separately in CI.
use AstrX\Image\ImageSanitizer;
use AstrX\Session\ServerSecret;
use AstrX\User\AvatarService;
use AstrX\User\UserRepository;
use AstrX\User\UserService;
use AstrX\User\UserSession;

require dirname(__DIR__) . '/src/autoload.php';
function check(string $name, bool $ok): void { if (!$ok) { throw new RuntimeException($name); } echo "ok: $name\n"; }
$pdo = PDO::connect('sqlite::memory:');
if (!$pdo instanceof Pdo\Sqlite) {
    throw new RuntimeException('Requires PHP 8.4+ with PDO SQLite.');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->createFunction('UNHEX', static fn (string $hex): string|false => hex2bin($hex), 1);
$pdo->exec('CREATE TABLE user (
    id BLOB PRIMARY KEY,
    totp_secret TEXT,
    totp_enabled INTEGER,
    totp_recovery TEXT,
    totp_last_step INTEGER DEFAULT 0
)');
$uid = str_repeat('ab', 16);
$stmt = $pdo->prepare('INSERT INTO user (id, totp_enabled) VALUES (UNHEX(:id), 0)');
$stmt->execute([':id' => $uid]);
$repo = new UserRepository($pdo);
$make = static function (string $key) use ($repo): UserService {
    $secret = new ServerSecret();
    $secret->setConfigured($key);
    return new UserService(
        $repo,
        new AvatarService($repo, new ImageSanitizer(), $secret),
        new UserSession(),
        $secret,
    );
};
$service = $make(str_repeat('a', 64));
$enabled = $service->enableTotp($uid, 'JBSWY3DPEHPK3PXP', []);
if (!$enabled->isOk() || $enabled->unwrap() !== true) {
    throw new RuntimeException('Could not establish the fixture TOTP state.');
}
check('enabled state is readable', $service->isTotpEnabled($uid)->isOk() && $service->isTotpEnabled($uid)->unwrap());
$wrongKey = $make(str_repeat('b', 64));
check('wrong key is unavailable', $wrongKey->totpInfo($uid)->isErr());
check('missing account is unavailable', $service->totpInfo(str_repeat('cd',16))->isErr());
foreach (["username TEXT DEFAULT 'admin'", "password TEXT", "mailbox TEXT", "email TEXT", "display_name TEXT", "type INT DEFAULT 1", "verified INT DEFAULT 1", "avatar INT DEFAULT 0", "login_attempts INT DEFAULT 0", "login_locked_until INT DEFAULT 0", "deleted INT DEFAULT 0", "deletion_mode TEXT DEFAULT 'none'", "theme TEXT"] as $column) {
    $pdo->exec('ALTER TABLE user ADD COLUMN ' . $column);
}
$pdo->prepare('UPDATE user SET password = :p')->execute([':p' => password_hash('correct-password', PASSWORD_ARGON2ID)]);
check('correct password cannot bypass unreadable factor', $wrongKey->login('admin', 'correct-password', false)->isErr());
$pdo->exec("UPDATE user SET totp_secret = 'enc:v1:corrupt'");
check('corrupt enabled secret is unavailable', $service->totpInfo($uid)->isErr());
$pdo->exec('UPDATE user SET totp_enabled = 0');
check('explicit disabled state works', $service->isTotpEnabled($uid)->isOk() && !$service->isTotpEnabled($uid)->unwrap());
$pdo->exec('UPDATE user SET totp_enabled = 2');
check('invalid flag is unavailable', $service->totpInfo($uid)->isErr());
$pdo->exec('ALTER TABLE user DROP COLUMN totp_last_step');
check('repository error is unavailable', $service->totpInfo($uid)->isErr());
foreach ([8,9,10,11] as $n) { check("reject $n characters", !AstrX\Auth\PasswordPolicy::accepts(str_repeat('x',$n))); }
check('accept 12 characters', AstrX\Auth\PasswordPolicy::accepts(str_repeat('x',12)));
check('counts Unicode characters', !AstrX\Auth\PasswordPolicy::accepts(str_repeat('界',4)));
check('accept Unicode passphrase', AstrX\Auth\PasswordPolicy::accepts(str_repeat('界',12)));
check('reject invalid UTF-8', !AstrX\Auth\PasswordPolicy::accepts(str_repeat("\xff",12)));
check('reject oversized input', !AstrX\Auth\PasswordPolicy::accepts(str_repeat('x',4097)));

// Extract actual installer functions without running the installers' main bodies.
function helper(string $file, string $name, string $namespace): void {
    $src = file_get_contents($file);
    $start = strpos($src, 'function ' . $name . '(');
    if ($start === false) { throw new RuntimeException('missing helper'); }
    $code = ''; $depth = 0; $started = false;
    foreach (token_get_all('<?php ' . substr($src, $start)) as $token) {
        if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) { $code .= $token[1]; } continue; }
        $code .= $token;
        if ($token === '{') { $depth++; $started = true; }
        if ($token === '}') { $depth--; if ($depth === 0 && $started) { break; } }
    }
    eval('namespace ' . $namespace . '; use PDO; use PDOException; ' . $code);
}
foreach (['Web' => 'public/setup.php', 'Cli' => 'tools/install.php'] as $ns => $path) {
    helper(dirname(__DIR__) . '/' . $path, 'makeAdmin', 'Review' . $ns);
    $call = 'Review' . $ns . '\\makeAdmin';
    foreach ([8,11] as $n) { check("$ns installer rejects $n before SQL", str_contains($call($pdo,'admin',str_repeat('x',$n),'admin'),'12 characters')); }
}
helper(dirname(__DIR__) . '/public/setup.php', 'setupToken', 'ReviewToken');
$dir = sys_get_temp_dir() . '/astrx-token-review-' . bin2hex(random_bytes(5)) . '/';
mkdir($dir,0700);
try {
    $token = ReviewToken\setupToken($dir);
    check('token persisted', file_get_contents($dir . '.setup_token') === $token && strlen($token) === 64);
    check('token reused', ReviewToken\setupToken($dir) === $token);
    check('token private', (fileperms($dir . '.setup_token') & 0777) === 0600);
    file_put_contents($dir . 'file', 'x');
    $threw = false;
    try { ReviewToken\setupToken($dir . 'file/'); } catch (RuntimeException) { $threw = true; }
    check('token persistence failure throws', $threw);
} finally { unlink($dir . '.setup_token'); unlink($dir . 'file'); rmdir($dir); }
