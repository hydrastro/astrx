<?php
declare(strict_types=1);

namespace AstrX\Config;

use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkInterface;
use AstrX\Config\Diagnostic\ConfigNotFoundDiagnostic;
use AstrX\Config\Diagnostic\ConfigFileInvalidDiagnostic;
use AstrX\Config\Diagnostic\ConfigSetterInvalidDiagnostic;
use AstrX\Config\Diagnostic\ConfigKeyUnusedDiagnostic;
use AstrX\Config\Diagnostic\ConfigNotABoolDiagnostic;
use AstrX\Support\ResourceRoots;
use ReflectionObject;
use function AstrX\Support\configDir;

final class Config
{
    /** @var array<string, array<string, mixed>> */
    private array $configuration = [];

    /**
     * Tracks which config keys have been consumed (either via getConfig() or
     * applyModuleConfig/InjectConfig). Keys: 'Domain.key'. Populated lazily.
     * @var array<string, true>
     */
    private array $consumedKeys = [];

    /** @var array<string, true> — domains whose keys have been checked for unused */
    private array $checkedDomains = [];

    /** Module config directories, lowest precedence first; the site's CONFIG_DIR sits above them. */
    private readonly ResourceRoots $roots;

    /**
     * @param ResourceRoots|null $roots the installed modules' resource
     *        directories: their config/ files are the defaults the site's own
     *        CONFIG_DIR files override key by key.
     */
    public function __construct(
        private readonly DiagnosticSinkInterface $sink,
        ?string $configFile = null,
        ?ResourceRoots $roots = null,
    ) {
        $this->roots = $roots ?? new ResourceRoots();
        $file = $configFile ?? (configDir() . 'config.php');

        $loaded = is_file($file) ? require $file : [];
        if (is_array($loaded)) {
            /** @var array<string, array<string, mixed>> $loaded */
            $this->configuration = $loaded;
        }

        // There used to be a register_shutdown_function() here that swept every
        // loaded domain for unused keys. It could not work, for two independent
        // reasons: it ran at script end, while its only consumer
        // (DefaultTemplateContext) renders diagnostics mid-page — so everything
        // it emitted went into a collector nobody read again — and it iterated a
        // set keyed by FILE base name, which for 25 of the 38 sections is not a
        // section name at all (loading Mail.config.php recorded 'Mail', so the
        // sweep looked up an empty array and checked nothing). Unused-key
        // detection now happens where it can be acted on: ModuleLoader emits it
        // mid-request for #[InjectConfig] classes, and tools/check_config.php
        // does the exhaustive sweep in CI with every consumer in view.
    }

    public function getConfig(string $domain, string $key, mixed $fallback = null): mixed
    {
        if (isset($this->configuration[$domain]) && array_key_exists($key, $this->configuration[$domain])) {
            $this->consumedKeys[$domain . '.' . $key] = true;
            return $this->configuration[$domain][$key];
        }

        if ($fallback !== null) {
            // A read that silently falls back is how a typo'd key, or a whole
            // section nobody ever created, stays invisible forever: every
            // getConfigBool/Int/String/Array supplies a default, so before this
            // the ONLY unmatched read that said anything was one with no default
            // at all. DEBUG, not WARNING: several of these are legitimate
            // "optional section" reads that would otherwise put a permanent
            // banner on every admin page. Below the default NOTICE threshold, so
            // an operator sees them by lowering the diagnostics level, and CI
            // sees all of them at once via tools/check_config.php.
            $this->sink->emit(new ConfigNotFoundDiagnostic(
                                  id:             'astrx.config/get_config.defaulted',
                                  level:          DiagnosticLevel::DEBUG,
                                  classShortName: $domain,
                                  configName:     $key,
                              ));

            return $fallback;
        }

        $this->sink->emit(new ConfigNotFoundDiagnostic(
                              id:            'astrx.config/get_config.not_found',
                              level:         DiagnosticLevel::WARNING,
                              classShortName: $domain,
                              configName:    $key,
                          ));

        return null;
    }

    /**
     * Load the config file {Domain}.config.php: each installed module's copy
     * (its defaults) first, then the site's CONFIG_DIR copy, merged per section
     * KEY BY KEY — so the site file only needs the keys it changes, and a key a
     * module adds later still reaches a site whose file predates it.
     *
     * Every section the file declares replaces the in-memory section of that
     * name wholesale, exactly as one file used to: reloading after an admin
     * save picks up removed keys too.
     */
    public function loadModuleConfig(string $domain): void
    {
        $layered = [];
        $found   = false;
        foreach ($this->configFilesFor($domain) as $path) {
            $loaded = require $path;
            if (!is_array($loaded)) {
                $this->sink->emit(new ConfigFileInvalidDiagnostic(
                                      id:    'astrx.config/config_file.invalid',
                                      level: DiagnosticLevel::ERROR,
                                      file:  $path,
                                  ));
                continue;
            }
            $found = true;
            foreach ($loaded as $section => $keys) {
                if (!is_string($section)) {
                    continue;
                }
                $prev = $layered[$section] ?? null;
                $layered[$section] = (is_array($prev) && is_array($keys)) ? array_merge($prev, $keys) : $keys;
            }
        }

        if (!$found) {
            return;
        }

        /** @var array<string, array<string, mixed>> $layered */
        $this->configuration = array_merge($this->configuration, $layered);
    }

    /**
     * The existing {Domain}.config.php files, lowest precedence first: the
     * modules' defaults, then the site's.
     *
     * @return list<string>
     */
    public function configFilesFor(string $domain): array
    {
        $out = [];
        foreach ([...$this->roots->of(ResourceRoots::CONFIG), configDir()] as $dir) {
            $path = $dir . $domain . '.config.php';
            if (file_exists($path)) {
                $out[] = $path;
            }
        }
        return $out;
    }

    /** Applies the config section for $domain to $instance. */
    public function applyModuleConfig(object $instance, string $domain): void
    {
        $cfg = $this->configuration[$domain] ?? null;
        if (!is_array($cfg)) {
            return;
        }
        $this->applyConfigToInstance($instance, $cfg, $domain);
    }

    /**
     * Emit diagnostics for any config keys in $domain that were never consumed.
     * Call once per domain after all classes for that domain have been created.
     * Safe to call multiple times — domains are only checked once.
     */
    public function emitUnusedKeyDiagnostics(string $domain): void
    {
        if (isset($this->checkedDomains[$domain])) {
            return;
        }
        $this->checkedDomains[$domain] = true;
        $cfg = $this->configuration[$domain] ?? [];
        foreach (array_keys($cfg) as $key) {
            if (!isset($this->consumedKeys[$domain . '.' . $key])) {
                $this->sink->emit(new ConfigKeyUnusedDiagnostic(
                                      id:     'astrx.config/key_unused',
                                      level:  DiagnosticLevel::WARNING,
                                      domain: $domain,
                                      key:    $key,
                                  ));
            }
        }
    }

    public function getConfigString(string $domain, string $key, string $default = ''): string
    {
        $v = $this->getConfig($domain, $key, $default);
        return is_string($v) ? $v : (is_scalar($v) ? (string)$v : $default);
    }

    public function getConfigInt(string $domain, string $key, int $default = 0): int
    {
        $v = $this->getConfig($domain, $key, $default);
        return is_int($v) ? $v : (is_numeric($v) ? (int)$v : $default);
    }

    /**
     * A config flag, read strictly.
     *
     * The old body was `is_bool($v) ? $v : (bool)$v`, i.e. a plain truthy cast.
     * Every non-empty string is truthy in PHP, so `'false'` and `'off'` both
     * came back TRUE — the exact opposite of what the file says. That is not
     * theoretical here: ModuleRegistry::enabled() rides on this method, so a
     * hand-edited `'chat' => 'false'` in Modules.config.php left the chat module
     * fully enabled while every human reading the file believed it was off.
     *
     * So: real booleans pass through; the unambiguous textual and 0/1 spellings
     * are parsed the way they read; anything else is a config error — reported,
     * and resolved to $default rather than to "not empty".
     */
    public function getConfigBool(string $domain, string $key, bool $default = false): bool
    {
        $v = $this->getConfig($domain, $key, $default);

        if (is_bool($v)) {
            return $v;
        }

        if ($v === 0 || $v === 1) {
            return $v === 1;
        }

        if (is_string($v)) {
            $parsed = match (strtolower(trim($v))) {
                '1', 'true', 'yes', 'on'        => true,
                '0', 'false', 'no', 'off', ''   => false,
                default                         => null,
            };
            if ($parsed !== null) {
                return $parsed;
            }
        }

        $this->sink->emit(new ConfigNotABoolDiagnostic(
                              id:     'astrx.config/not_a_bool',
                              level:  DiagnosticLevel::ERROR,
                              domain: $domain,
                              key:    $key,
                              actual: get_debug_type($v),
                          ));

        return $default;
    }

    /**
     * @param array<string,mixed> $default
     * @return array<string,mixed>
     */
    public function getConfigArray(string $domain, string $key, array $default = []): array
    {
        $v = $this->getConfig($domain, $key, $default);
        if (!is_array($v)) { return $default; }
        /** @var array<string,mixed> $v */
        return $v;
    }

    /** @return array<string,array<string,mixed>> */
    public function getConfigSection(string $domain): array
    {
        $section = $this->configuration[$domain] ?? [];
        /** @var array<string,array<string,mixed>> $section */
        return $section;
    }

    /** @param array<string, mixed> $cfg */
    private function applyConfigToInstance(object $instance, array $cfg, string $configDomain = ''): void
    {
        if ($instance instanceof ConfigurableInterface) {
            $instance->applyConfig($cfg);
            return;
        }

        $rc = new ReflectionObject($instance);

        foreach ($rc->getMethods() as $method) {
            $attrs = $method->getAttributes(InjectConfig::class);
            if ($attrs === []) {
                continue;
            }

            $key = $attrs[0]->newInstance()->key;
            if (!array_key_exists($key, $cfg)) {
                // The setter declares a key that does not exist in the loaded
                // config for this domain. This is the mirror of an unused key:
                // a typo in the attribute name or a removed config key whose
                // setter was not cleaned up.
                $this->sink->emit(new ConfigNotFoundDiagnostic(
                                      id:             'astrx.config/setter.key_missing',
                                      level:          DiagnosticLevel::WARNING,
                                      classShortName: $rc->getName(),
                                      configName:     $key,
                                  ));
                continue;
            }
            // Mark as consumed so unused-key detection doesn't flag it
            $this->consumedKeys[$configDomain . '.' . $key] = true;

            if ($method->getNumberOfParameters() !== 1) {
                $this->sink->emit(new ConfigSetterInvalidDiagnostic(
                                      id:         'astrx.config/setter.invalid',
                                      level:      DiagnosticLevel::WARNING,
                                      className:  $rc->getName(),
                                      methodName: $method->getName(),
                                  ));
                continue;
            }

            $method->invoke($instance, $cfg[$key]);
        }
    }}
