<?php
declare(strict_types=1);

namespace AstrX\Config;

use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkInterface;
use AstrX\Config\Diagnostic\ConfigNotFoundDiagnostic;
use AstrX\Config\Diagnostic\ConfigFileInvalidDiagnostic;
use AstrX\Config\Diagnostic\ConfigSetterInvalidDiagnostic;
use AstrX\Config\Diagnostic\ConfigKeyUnusedDiagnostic;
use ReflectionObject;
use function AstrX\Support\configDir;

final class Config
{
    /** @var array<string, array<string, mixed>> */
    private array $configuration;

    /**
     * Tracks which config keys have been consumed (either via getConfig() or
     * applyModuleConfig/InjectConfig). Keys: 'Domain.key'. Populated lazily.
     * @var array<string, true>
     */
    private array $consumedKeys = [];

    /** @var array<string, true> — domains whose keys have been checked for unused */
    private array $checkedDomains = [];

    /** @var array<string, true> — every domain that has been loaded via loadModuleConfig() */
    private array $loadedDomains = [];

    public function __construct(
        private readonly DiagnosticSinkInterface $sink,
        ?string $configFile = null,
    ) {
        $file = $configFile ?? (configDir() . 'config.php');

        $loaded = is_file($file) ? require $file : [];
        if (is_array($loaded)) {
            /** @var array<string, array<string, mixed>> $loaded */
            $this->configuration = $loaded;
        }

        // Register a shutdown function to detect unused config keys in domains
        // that are read via getConfig() rather than #[InjectConfig] setters.
        // Those domains are consumed lazily during the request and can only be
        // checked reliably at script-end, after all reads have completed.
        // ModuleLoader handles #[InjectConfig] domains eagerly; this catches the rest.
        register_shutdown_function(function (): void {
            foreach (array_keys($this->loadedDomains) as $domain) {
                $this->emitUnusedKeyDiagnostics($domain);
            }
        });
    }

    public function getConfig(string $domain, string $key, mixed $fallback = null): mixed
    {
        if (isset($this->configuration[$domain]) && array_key_exists($key, $this->configuration[$domain])) {
            $this->consumedKeys[$domain . '.' . $key] = true;
            return $this->configuration[$domain][$key];
        }

        if ($fallback !== null) {
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

    /** Loads optional per-module config file: CONFIG_DIR/{Domain}.config.php */
    public function loadModuleConfig(string $domain): void
    {
        $path = (configDir()) . $domain . '.config.php';
        if (!file_exists($path)) {
            return;
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            $this->sink->emit(new ConfigFileInvalidDiagnostic(
                                  id:    'astrx.config/config_file.invalid',
                                  level: DiagnosticLevel::ERROR,
                                  file:  $path,
                              ));
            return;
        }

        /** @var array<string, array<string, mixed>> $loaded */
        $this->configuration = array_merge($this->configuration, $loaded);
        $this->loadedDomains[$domain] = true;
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

    public function getConfigBool(string $domain, string $key, bool $default = false): bool
    {
        $v = $this->getConfig($domain, $key, $default);
        return is_bool($v) ? $v : (bool)$v;
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
