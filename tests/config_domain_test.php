<?php
declare(strict_types=1);

/**
 * Config layer — section/file routing, strict flags, injector resolution.
 * NO AstrX bootstrap, no database.
 *
 * What this pins down, one regression each:
 *
 *  1. ConfigWriter routes a section to the file that DECLARES it, not to the
 *     file name the caller passed. The webmail admin page read section
 *     `ImapClient` out of Mail.config.php and wrote it to Imap.config.php — a
 *     file no code path loads — so every IMAP setting, including the SOCKS5
 *     host/port that puts IMAP on Tor, was write-only.
 *  2. ModuleLoader loads EVERY candidate file before applying ANY section, so a
 *     class whose section lives in the parent's file still gets configured.
 *  3. Config::getConfigBool() parses 'false' as false. It used to `(bool)` it,
 *     and every non-empty string is truthy in PHP.
 *  4. Injector resolves an OPTIONAL class-typed constructor parameter instead of
 *     skipping straight to its null default.
 *  5. Injector::createClass() keeps a throwing/abstract/private constructor
 *     inside the Result envelope instead of letting a raw Error escape.
 *
 * Run:  php tests/config_domain_test.php
 */

namespace {

    use AstrX\Config\Config;
    use AstrX\Config\ConfigDomainResolver;
    use AstrX\Config\ConfigWriter;
    use AstrX\I18n\Translator;
    use AstrX\Injector\Injector;
    use AstrX\Module\ModuleLoader;
    use AstrX\Result\DiagnosticsCollector;

    $ROOT      = dirname(__DIR__);
    $CLASS_DIR = $ROOT . '/src/AstrX/';

    spl_autoload_register(static function (string $class) use ($CLASS_DIR): void {
        if (strncmp($class, 'AstrX\\', 6) !== 0) { return; }
        $file = $CLASS_DIR . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) { require_once $file; }
    });
    require_once $CLASS_DIR . 'Support/constants.php';

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

    // A throwaway config directory, so nothing here touches resources/config/.
    $TMP = sys_get_temp_dir() . '/astrx-cfgtest-' . bin2hex(random_bytes(6)) . '/';
    mkdir($TMP, 0700, true);
    register_shutdown_function(static function () use ($TMP): void {
        foreach (glob($TMP . '*') ?: [] as $f) { @unlink($f); }
        @rmdir($TMP);
    });

    // CONFIG_DIR is what ConfigWriter and Config resolve their paths through.
    if (!defined('CONFIG_DIR')) { define('CONFIG_DIR', $TMP); }

    // Grouped file: the section name and the file name differ, exactly like
    // Mail.config.php declaring 'Mailer' / 'ImapClient' / 'WebmailService'.
    file_put_contents($TMP . 'Grouped.config.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        return [
            'FakeImapClient' => [
                'imap_socks5_host' => '',
                'imap_socks5_port' => 0,
                'keep_me'          => 'untouched',
            ],
        ];
        PHP);

    echo "\n== ConfigWriter section routing ==\n";

    $resolver = new ConfigDomainResolver($TMP);
    eq('resolver finds the file that declares a section', 'Grouped', $resolver->fileForSection('FakeImapClient'));
    eq('resolver reports null for a section nobody declares', null, $resolver->fileForSection('Nonexistent'));

    $writer = new ConfigWriter(new ConfigDomainResolver($TMP));

    // The bug: a caller that names the wrong file.
    $result = $writer->write('Imap', [
        'FakeImapClient' => ['imap_socks5_host' => '127.0.0.1', 'imap_socks5_port' => 9050],
    ]);

    check('write() succeeds', $result->isOk());
    check(
        'the mis-named target file is NOT created',
        !is_file($TMP . 'Imap.config.php'),
    );

    /** @var array<string,array<string,mixed>> $reloaded */
    $reloaded = require $TMP . 'Grouped.config.php';
    eq('the setting lands in the file the loader reads', '127.0.0.1', $reloaded['FakeImapClient']['imap_socks5_host']);
    eq('…including the port', 9050, $reloaded['FakeImapClient']['imap_socks5_port']);
    eq('…and keys the form did not post survive', 'untouched', $reloaded['FakeImapClient']['keep_me']);

    $retargeted = false;
    foreach ($result->diagnostics() as $d) {
        if ($d->id() === 'astrx.config/write_retargeted') { $retargeted = true; }
    }
    check('the redirect is reported, not silent', $retargeted);

    // A brand-new section nobody declares still lands where the caller asked.
    $writer->write('Fresh', ['BrandNewSection' => ['a' => 1]]);
    check('an undeclared section is created in the requested file', is_file($TMP . 'Fresh.config.php'));

    echo "\n== ModuleLoader: load every file before applying any section ==\n";

    file_put_contents($TMP . 'config.php', "<?php\nreturn [];\n");

    $collector  = new DiagnosticsCollector();
    $config     = new Config($collector, $TMP . 'config.php');
    $translator = new Translator($collector);
    $loader     = new ModuleLoader($config, $translator, $collector, sys_get_temp_dir() . '/astrx-nolang/');

    $target = new FakeImapClient();
    $loader->onClassCreated($target, FakeImapClient::class);

    eq(
        'a class whose section lives in another file still gets configured',
        '127.0.0.1',
        $target->socks5Host,
    );
    eq('…and its int key', 9050, $target->socks5Port);

    echo "\n== Config::getConfigBool is not a truthy cast ==\n";

    file_put_contents($TMP . 'Flags.config.php', <<<'PHP'
        <?php
        declare(strict_types=1);
        return [
            'Flags' => [
                'real_false'   => false,
                'string_false' => 'false',
                'string_off'   => 'off',
                'string_true'  => 'true',
                'int_zero'     => 0,
                'int_one'      => 1,
                'nonsense'     => 'perhaps',
            ],
        ];
        PHP);

    $flagCollector = new DiagnosticsCollector();
    $flagConfig    = new Config($flagCollector, $TMP . 'config.php');
    $flagConfig->loadModuleConfig('Flags');

    eq('a real false is false',      false, $flagConfig->getConfigBool('Flags', 'real_false', true));
    eq("'false' is false",           false, $flagConfig->getConfigBool('Flags', 'string_false', true));
    eq("'off' is false",             false, $flagConfig->getConfigBool('Flags', 'string_off', true));
    eq("'true' is true",             true,  $flagConfig->getConfigBool('Flags', 'string_true', false));
    eq('0 is false',                 false, $flagConfig->getConfigBool('Flags', 'int_zero', true));
    eq('1 is true',                  true,  $flagConfig->getConfigBool('Flags', 'int_one', false));
    eq('an unparseable value takes the default, not "truthy"',
        false, $flagConfig->getConfigBool('Flags', 'nonsense', false));

    $notABool = false;
    foreach ($flagCollector->diagnostics() as $d) {
        if ($d->id() === 'astrx.config/not_a_bool') { $notABool = true; }
    }
    check('an unparseable flag is reported', $notABool);

    echo "\n== Config reports a read that fell back to its default ==\n";

    $defCollector = new DiagnosticsCollector();
    $defConfig    = new Config($defCollector, $TMP . 'config.php');
    $defConfig->getConfigString('NoSuchSection', 'no_such_key', 'fallback');

    $defaulted = false;
    foreach ($defCollector->diagnostics() as $d) {
        if ($d->id() === 'astrx.config/get_config.defaulted') { $defaulted = true; }
    }
    check('a key read but never declared is no longer silent', $defaulted);

    echo "\n== Injector: optional parameters and escaping errors ==\n";

    $injector = new Injector();
    $dep      = new InjectableDependency();
    $injector->setClass($dep);

    $optional = $injector->createClass(NeedsOptionalDependency::class);
    check('a class with an optional class-typed parameter builds', $optional->isOk());
    if ($optional->isOk()) {
        $built = $optional->unwrap();
        check(
            'an optional class-typed parameter is RESOLVED, not left null',
            $built instanceof NeedsOptionalDependency && $built->dep === $dep,
        );
    }

    $unresolvable = $injector->createClass(NeedsOptionalUnresolvable::class);
    check('an optional parameter the container cannot supply falls back to its default',
        $unresolvable->isOk());
    if ($unresolvable->isOk()) {
        $built = $unresolvable->unwrap();
        check('…and that default is the declared one',
            $built instanceof NeedsOptionalUnresolvable && $built->label === 'default');
    }

    foreach ([
        'a throwing constructor'  => ThrowingConstructor::class,
        'an abstract class'       => AbstractDependency::class,
        'a private constructor'   => PrivateConstructor::class,
    ] as $label => $class) {
        $escaped = null;
        try {
            $r = $injector->createClass($class);
            $escaped = $r->isOk() ? 'unexpectedly ok' : null;
        } catch (\Throwable $t) {
            $escaped = get_class($t) . ' escaped the Result envelope';
        }
        check("{$label} stays inside the Result envelope", $escaped === null);
    }

    echo "\n{$PASS} passed, {$FAIL} failed\n";
    exit($FAIL === 0 ? 0 : 1);
}

namespace {

    use AstrX\Config\ConfigDomain;
    use AstrX\Config\InjectConfig;

    /** Stands in for AstrX\Mail\ImapClient: section declared in another file. */
    #[ConfigDomain('FakeImapClient', file: 'Grouped')]
    final class FakeImapClient
    {
        public string $socks5Host = '';
        public int    $socks5Port = 0;

        #[InjectConfig('imap_socks5_host')]
        public function setSocks5Host(string $v): void { $this->socks5Host = $v; }

        #[InjectConfig('imap_socks5_port')]
        public function setSocks5Port(int $v): void { $this->socks5Port = $v; }
    }

    final class InjectableDependency {}

    final class NeedsOptionalDependency
    {
        public function __construct(public readonly ?InjectableDependency $dep = null) {}
    }

    final class NeedsOptionalUnresolvable
    {
        public function __construct(public readonly string $label = 'default') {}
    }

    final class ThrowingConstructor
    {
        public function __construct()
        {
            throw new \RuntimeException('constructor exploded');
        }
    }

    abstract class AbstractDependency {}

    final class PrivateConstructor
    {
        private function __construct() {}
    }
}
