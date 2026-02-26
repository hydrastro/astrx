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
    private string $lang;

    /** @var array<string, array<string, mixed>> */
    private array $configuration;

    /** @var list<string> */
    private array $deferredLangClasses = [];

    private DiagnosticSinkInterface $sink;

    public function __construct(DiagnosticSinkInterface $sink)
    {
        $this->configuration = require(CONFIG_DIR . "config.php");
        $this->sink = $sink;
    }

    public function getConfig(string $classShortName, string $configName, mixed $fallback = null): mixed
    {
        if (isset($this->configuration[$classShortName]) && array_key_exists($configName, $this->configuration[$classShortName])) {
            return $this->configuration[$classShortName][$configName];
        }

        if ($fallback !== null) {
            return $fallback;
        }

        $this->sink->emit(new ConfigNotFoundDiagnostic(
                              id: 'astrx.config/get_config.not_found',
                              level: DiagnosticLevel::WARNING,
                              classShortName: $classShortName,
                              configName: $configName
                          ));

        return null;
    }

    public function addDeferredLangClass(object $class): void
    {
        $this->deferredLangClasses[] = get_class($class);
    }

    public function setLangAndLoadDeferred(string $lang): bool
    {
        $languages = $this->getConfig("Prelude", "available_languages");
        if (!is_array($languages) || !in_array($lang, $languages, true)) {
            return false;
        }

        $this->lang = $lang;

        $general = LANG_DIR . "$lang.php";
        if (file_exists($general)) {
            require $general;
        }

        foreach ($this->deferredLangClasses as $fqcn) {
            $this->loadLangForFqcn($fqcn);
        }

        // after language is set, no more deferrals are needed
        $this->deferredLangClasses = [];

        return true;
    }

    /**
     * Injector helper hook. Signature: (object $instance, string $className)
     * Loads config/lang and applies config.
     */
    public function onClassCreated(object $instance, string $className): void
    {
        // 1) lang
        if (isset($this->lang)) {
            $this->loadLangForFqcn($className);
        } else {
            $this->deferredLangClasses[] = $className;
        }

        // 2) per-class config file merge
        $this->loadConfigForFqcn($className);

        // 3) apply config values to instance (if any)
        $short = (new \ReflectionClass($className))->getShortName();
        $cfg = $this->configuration[$short] ?? null;
        if (is_array($cfg)) {
            $this->applyConfigToInstance($instance, $cfg);
        }
    }

    private function loadLangForFqcn(string $fqcn): void
    {
        $short = (new \ReflectionClass($fqcn))->getShortName();
        $lang = $this->lang;

        $file = LANG_DIR . "$lang/$short.$lang.php";
        if (file_exists($file)) {
            require_once $file;
        }
    }

    private function loadConfigForFqcn(string $fqcn): void
    {
        $short = (new \ReflectionClass($fqcn))->getShortName();
        $path = CONFIG_DIR . "$short.config.php";

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

        // merge whole tree
        $this->configuration = array_merge($this->configuration, $loaded);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function applyConfigToInstance(object $instance, array $cfg): void
    {
        // 1) explicit interface
        if ($instance instanceof ConfigurableInterface) {
            $instance->applyConfig($cfg);
            return;
        }

        $rc = new \ReflectionObject($instance);

        // 2) property injection
        foreach ($rc->getProperties() as $prop) {
            $attrs = $prop->getAttributes(InjectConfig::class);
            if ($attrs === []) continue;

            $key = $attrs[0]->newInstance()->key;
            if (!array_key_exists($key, $cfg)) continue;

            $prop->setAccessible(true);
            $prop->setValue($instance, $cfg[$key]);
        }

        // 3) setter injection
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