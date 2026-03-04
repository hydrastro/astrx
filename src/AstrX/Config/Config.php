<?php
declare(strict_types=1);

namespace AstrX\Config;

use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkInterface;
use AstrX\Config\Diagnostic\ConfigNotFoundDiagnostic;
use AstrX\Config\Diagnostic\ConfigFileInvalidDiagnostic;
use AstrX\Config\Diagnostic\ConfigSetterInvalidDiagnostic;

final class Config
{
    /** @var array<string, array<string, mixed>> */
    private array $configuration;

    private DiagnosticSinkInterface $sink;

    public function __construct(DiagnosticSinkInterface $sink)
    {
        $this->configuration = require(CONFIG_DIR . "config.php");
        $this->sink = $sink;
    }

    public function getConfig(string $domain, string $key, mixed $fallback = null): mixed
    {
        if (isset($this->configuration[$domain]) && array_key_exists($key, $this->configuration[$domain])) {
            return $this->configuration[$domain][$key];
        }

        if ($fallback !== null) {
            return $fallback;
        }

        $this->sink->emit(new ConfigNotFoundDiagnostic(
                              id: 'astrx.config/get_config.not_found',
                              level: DiagnosticLevel::WARNING,
                              classShortName: $domain,
                              configName: $key
                          ));

        return null;
    }

    /**
     * Loads optional per-module config file: CONFIG_DIR/{Domain}.config.php
     */
    public function loadModuleConfig(string $domain): void
    {
        // todo: check for annotation / interface and do deterministic loading
        $path = CONFIG_DIR . $domain . ".config.php";
        if (!file_exists($path)) {
            return;
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            $this->sink->emit(new ConfigFileInvalidDiagnostic(
                                  id: 'astrx.config/config_file.invalid',
                                  level: DiagnosticLevel::ERROR,
                                  file: $path
                              ));
            return;
        }

        $this->configuration = array_merge($this->configuration, $loaded);
    }

    /**
     * Applies the config section for $domain to $instance.
     */
    public function applyModuleConfig(object $instance, string $domain): void
    {
        $cfg = $this->configuration[$domain] ?? null;
        if (!is_array($cfg)) {
            return;
        }
        $this->applyConfigToInstance($instance, $cfg);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function applyConfigToInstance(object $instance, array $cfg): void
    {
        if ($instance instanceof ConfigurableInterface) {
            $instance->applyConfig($cfg);
            return;
        }

        $rc = new \ReflectionObject($instance);

        foreach ($rc->getProperties() as $prop) {
            $attrs = $prop->getAttributes(InjectConfig::class);
            if ($attrs === []) continue;

            $key = $attrs[0]->newInstance()->key;
            if (!array_key_exists($key, $cfg)) continue;

            $prop->setAccessible(true);
            $prop->setValue($instance, $cfg[$key]);
        }

        foreach ($rc->getMethods() as $method) {
            $attrs = $method->getAttributes(InjectConfig::class);
            if ($attrs === []) continue;

            $key = $attrs[0]->newInstance()->key;
            if (!array_key_exists($key, $cfg)) continue;

            if ($method->getNumberOfParameters() !== 1) {
                $this->sink->emit(new ConfigSetterInvalidDiagnostic(
                                      id: 'astrx.config/setter.invalid',
                                      level: DiagnosticLevel::WARNING,
                                      className: $rc->getName(),
                                      methodName: $method->getName()
                                  ));
                continue;
            }

            $method->setAccessible(true);
            $method->invoke($instance, $cfg[$key]);
        }
    }
}