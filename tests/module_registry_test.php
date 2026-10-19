<?php
declare(strict_types=1);

/**
 * ModuleRegistry: explicit installation, explicit enabling.
 *
 *   1. A registry built with no module list knows no modules (it never scans
 *      directories), so an unconfigured container activates nothing.
 *   2. Installed + flag `true` = on; installed + flag `false` = off;
 *      installed + NO flag = off; not installed = off even with a flag.
 *   3. A module whose code is present but which is not listed contributes
 *      nothing: no key, no controllers, no permissions.
 *   3c. Extension points: core asks the registry which modules answer an
 *      interface and gets only the enabled ones (all installed ones when it
 *      asks about stored data).
 *   4. Bad lists fail loudly: a class that is not a Module, a duplicate key.
 *   5. The application's own list matches the modules on disk and every one
 *      of them has a flag.
 *
 * Standalone: no bootstrap, no database.
 */

use AstrX\ApplicationModules;
use AstrX\Config\Config;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleRegistry;
use AstrX\Result\DiagnosticsCollector;

$root = dirname(__DIR__);
$dir  = rtrim(sys_get_temp_dir(), '/') . '/astrx-modreg-' . bin2hex(random_bytes(5)) . '/';
mkdir($dir, 0o700);
define('CONFIG_DIR', $dir);
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

/**
 * A registry over $modules with Modules.config.php holding exactly $flags.
 *
 * @param list<string>        $modules
 * @param array<string, bool> $flags
 */
function registry(array $modules, array $flags): ModuleRegistry
{
    file_put_contents(CONFIG_DIR . 'config.php', '<?php return [];');
    file_put_contents(CONFIG_DIR . 'Modules.config.php', '<?php return ' . var_export(['Modules' => $flags], true) . ';');
    $config = new Config(new DiagnosticsCollector(), CONFIG_DIR . 'config.php');
    return new ModuleRegistry($config, new Injector(), $modules);
}

function threw(callable $fn): bool
{
    try { $fn(); } catch (LogicException) { return true; }
    return false;
}

try {
    echo "1. No list, no modules\n";
    $empty = registry([], ['chat' => true, 'imageboard' => true]);
    check('an unconfigured registry has no modules', $empty->moduleKeys() === []);
    check('a flag alone does not enable an uninstalled module', !$empty->enabled('chat'));
    check('and contributes no controllers', $empty->controllers() === []);

    echo "\n2. Installed vs enabled\n";
    $both = [AstrX\Chat\ChatModule::class, AstrX\Imageboard\ImageboardModule::class];
    $r = registry($both, ['chat' => true, 'imageboard' => false]);
    check('installed + true is on', $r->enabled('chat'));
    check('installed + false is off', !$r->enabled('imageboard'));
    $r = registry($both, ['chat' => true]);
    check('installed + NO flag is off', !$r->enabled('imageboard'));
    check('a disabled module still declares its controllers (static knowledge)',
        isset($r->controllers()['board']));
    check('a disabled module still declares its permissions (grants editor)',
        in_array(AstrX\Imageboard\ImageboardPermission::class, $r->permissionEnums(), true));
    check('but only enabled modules register policies (chat\'s, not the board\'s)',
        $r->enabledPolicies() === [AstrX\Chat\ChatMessageResource::class => AstrX\Chat\ChatPolicy::class]);
    check('a key that is not installed is off', !$r->enabled('webmail'));

    echo "\n3. Present but not installed\n";
    $r = registry([AstrX\Chat\ChatModule::class], ['chat' => true, 'webmail' => true]);
    check('webmail code is present', class_exists(AstrX\Webmail\WebmailModule::class));
    check('yet it has no key', !in_array('webmail', $r->moduleKeys(), true));
    check('no controllers', !isset($r->controllers()['webmail']));
    check('no permissions', !in_array(AstrX\Webmail\WebmailPermission::class, $r->permissionEnums(), true));

    echo "\n3b. Whose pages are live (the page gate, navbar and JS manifest share this)\n";
    $r = registry($both, ['chat' => true, 'imageboard' => false]);
    check('a core page (no owner) is live', $r->pageOwnerEnabled(''));
    check("an enabled module's page is live", $r->pageOwnerEnabled('chat'));
    check('the owner is matched case- and whitespace-insensitively', $r->pageOwnerEnabled(' Chat '));
    check("a disabled module's page is not", !$r->pageOwnerEnabled('imageboard'));
    check("an uninstalled module's page is not (its rows outlive it)", !$r->pageOwnerEnabled('webmail'));
    check('an owner nobody claims is not (fail closed)', !$r->pageOwnerEnabled('nope'));

    echo "\n3c. Extension points\n";
    $r = registry($both, ['chat' => true, 'imageboard' => false]);
    $sources = $r->extensions(AstrX\Retention\RetentionSource::class);
    check('an enabled module answers an extension point',
        count($sources) === 1 && $sources[0] instanceof AstrX\Chat\ChatRetention);
    $sources = $r->extensions(AstrX\Retention\RetentionSource::class, installedToo: true);
    check('a disabled one too, when the question is about its stored data',
        array_map(static fn(object $o): string => $o::class, $sources)
            === [AstrX\Chat\ChatRetention::class, AstrX\Imageboard\ImageboardRetention::class]);
    check('a point no module answers yields nothing',
        $r->extensions(Stringable::class) === []);
    $retention = new AstrX\Retention\RetentionService(new PDO('sqlite::memory:'), $r);
    check("retention's targets are the modules' (chat's, with the board's disabled)",
        array_column($retention->targets(), 'key') === ['chat_report', 'chat_message', 'chat_pm']);
    check('a retention target is a plain identifier, never SQL',
        threw(static fn() => new AstrX\Retention\RetentionTarget('k', 'x`; DROP', 'c', AstrX\Retention\RetentionTarget::AGE)));
    check('and its mode is age or expiry',
        threw(static fn() => new AstrX\Retention\RetentionTarget('k', 't', 'c', 'never')));

    echo "\n4. Bad lists fail loudly\n";
    check('a class that is not a Module', threw(static fn() => registry([stdClass::class], [])->moduleKeys()));
    check('a missing class', threw(static fn() => registry(['AstrX\\Nope\\NopeModule'], [])->moduleKeys()));
    check('the same module twice (duplicate key)',
        threw(static fn() => registry([AstrX\Chat\ChatModule::class, AstrX\Chat\ChatModule::class], [])->moduleKeys()));

    echo "\n5. The application's list\n";
    $onDisk = [];
    foreach (glob($root . '/src/AstrX/*/*Module.php') ?: [] as $file) {
        $class = 'AstrX\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
        if (is_subclass_of($class, AstrX\Module\Module::class)) {
            $onDisk[] = $class;
        }
    }
    sort($onDisk);
    $installed = ApplicationModules::INSTALLED;
    sort($installed);
    check('every module on disk is installed (this site uses them all)', $onDisk === $installed,
        'not installed: ' . implode(', ', array_diff($onDisk, $installed)));
    /** @var array{Modules: array<string, bool>} $flags */
    $flags = require $root . '/resources/config/Modules.config.php';
    $real  = registry(ApplicationModules::INSTALLED, $flags['Modules']);
    $noFlag = array_values(array_diff($real->moduleKeys(), array_keys($flags['Modules'])));
    check('every installed module has a flag in Modules.config.php', $noFlag === [], implode(', ', $noFlag));
    check('with the shipped config, every module is on',
        $real->disabledModules() === [], implode(', ', $real->disabledModules()));
} finally {
    array_map('unlink', glob($dir . '*') ?: []);
    rmdir($dir);
}

echo "\n" . str_repeat('=', 40) . "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
