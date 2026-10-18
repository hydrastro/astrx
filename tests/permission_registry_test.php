<?php
declare(strict_types=1);

/**
 * Per-feature permissions (Auth\Permission implemented by each feature's enum).
 *
 *   1. The registry built the way Prelude builds it (ApplicationPermissions::CORE
 *      plus every module manifest) exposes EXACTLY the 58 identifiers the old
 *      single Permission enum had. Grants are stored by identifier, so losing
 *      one would silently revoke it and the grants editor would drop it on save.
 *   2. Each enum lives in its feature's namespace, implements the contract and
 *      id() equals its value.
 *   3. Every grant in resources/config/Auth.config.php names a real permission
 *      (a wildcard must match at least one) — a typo there is a grant nobody gets.
 *   4. The registry rejects duplicates and non-permission classes.
 *
 * Standalone: no bootstrap, no database.
 */

use AstrX\ApplicationPermissions;
use AstrX\Auth\Permission;
use AstrX\Auth\PermissionRegistry;

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

$PASS = 0;
$FAIL = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  ok   - {$label}\n";
    } else {
        $FAIL++;
        echo "  FAIL - {$label}" . ($detail !== '' ? "\n         {$detail}" : '') . "\n";
    }
}

/** The identifiers of the former AstrX\Auth\Permission enum (sorted). */
const LEGACY_IDS = [
    'admin.access',
    'admin.audit_log',
    'admin.banlist',
    'admin.canary',
    'admin.comments',
    'admin.config.access',
    'admin.config.captcha',
    'admin.config.chat',
    'admin.config.comments',
    'admin.config.content',
    'admin.config.imageboard',
    'admin.config.mail',
    'admin.config.system',
    'admin.config.users',
    'admin.downloads',
    'admin.mirrors',
    'admin.navbar',
    'admin.news',
    'admin.notes',
    'admin.pages',
    'admin.panic',
    'admin.retention',
    'admin.tipline',
    'admin.users',
    'api.key.create',
    'api.key.revoke',
    'ban.create',
    'ban.revoke',
    'ban.view',
    'board.admin',
    'board.delete.own',
    'board.moderate',
    'board.post',
    'board.view',
    'chat.delete.any',
    'chat.delete.own',
    'chat.moderate',
    'chat.pm',
    'chat.post',
    'chat.view',
    'comment.delete.any',
    'comment.delete.own',
    'comment.flag',
    'comment.hide.any',
    'comment.hide.own',
    'comment.post',
    'news.create',
    'news.delete.any',
    'news.edit.any',
    'news.view',
    'user.delete.any',
    'user.delete.own',
    'user.edit.any',
    'user.edit.own',
    'user.promote',
    'user.view.public',
    'webmail.access',
    'webmail.send',
];

$enums = ApplicationPermissions::CORE;
foreach (glob($root . '/src/AstrX/*/module.php') ?: [] as $file) {
    $manifest = require $file;
    if (is_array($manifest) && is_array($manifest['permissions'] ?? null)) {
        foreach ($manifest['permissions'] as $enum) {
            $enums[] = $enum;
        }
    }
}
$registry = new PermissionRegistry($enums);
$ids = array_map(static fn(Permission $p): string => $p->id(), $registry->all());

echo "1. Same identifiers as before the split\n";
check('58 permissions', count($ids) === 58, (string) count($ids));
check('exactly the legacy identifiers', $ids === LEGACY_IDS,
    'missing: ' . implode(', ', array_diff(LEGACY_IDS, $ids)) . '; extra: ' . implode(', ', array_diff($ids, LEGACY_IDS)));

echo "\n2. Each enum belongs to its feature\n";
$misplaced = [];
$badId     = [];
foreach ($enums as $enum) {
    // AstrX\<Feature>\<Feature or prefix>Permission
    if (!preg_match('~^AstrX\\\\[A-Za-z]+\\\\[A-Za-z]+Permission$~', $enum)) {
        $misplaced[] = $enum;
    }
    foreach ($enum::cases() as $case) {
        if (!$case instanceof Permission || $case->id() !== $case->value) {
            $badId[] = $enum . '::' . $case->name;
        }
    }
}
check('every enum is AstrX\\<Feature>\\<Name>Permission', $misplaced === [], implode(', ', $misplaced));
check('id() === value for every case', $badId === [], implode(', ', $badId));

echo "\n3. Auth.config.php grants name real permissions\n";
/** @var array{Gate?: array{grants?: array<string, list<string>>}} $auth */
$auth   = require $root . '/resources/config/Auth.config.php';
$grants = $auth['Gate']['grants'] ?? [];
$unknown = [];
foreach ($grants as $role => $patterns) {
    foreach ($patterns as $pattern) {
        if ($pattern === '*') {
            continue;
        }
        $matches = str_ends_with($pattern, '.*')
            ? array_filter($ids, static fn(string $id): bool => str_starts_with($id, substr($pattern, 0, -1)))
            : array_filter($ids, static fn(string $id): bool => $id === $pattern);
        if ($matches === []) {
            $unknown[] = "{$role}: {$pattern}";
        }
    }
}
check('found the configured roles', count($grants) >= 3, implode(', ', array_keys($grants)));
check('no grant names an unknown permission', $unknown === [], implode('; ', $unknown));

echo "\n4. Registry guards\n";
$threw = static function (callable $fn): bool {
    try { $fn(); } catch (LogicException) { return true; }
    return false;
};
check('a non-permission class is rejected', $threw(static fn() => new PermissionRegistry([stdClass::class])));
check('the same enum listed twice is harmless', count((new PermissionRegistry([
    AstrX\Chat\ChatPermission::class, AstrX\Chat\ChatPermission::class,
]))->all()) === count(AstrX\Chat\ChatPermission::cases()));
check('get() finds a permission by id', $registry->get('chat.moderate') === AstrX\Chat\ChatPermission::MODERATE);

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
