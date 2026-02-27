<?php

declare(strict_types = 1);

namespace AstrX\Module;

use AstrX\Config\Config;
use AstrX\I18n\Translator;

final class ModuleLoader
{
    private Config $config;
    private Translator $translator;
    /** @var array<string, true> */
    private array $pendingLangDomains = [];
    private bool $localeSet = false;

    public function __construct(Config $config, Translator $translator)
    {
        $this->config = $config;
        $this->translator = $translator;
    }

    /**
     * Boundary: called after ContentManager decides the locale.
     * Loads all deferred language domains.
     */
    public function setLocale(string $locale)
    : void {
        $this->translator->setLocale($locale);
        $this->localeSet = true;

        foreach (array_keys($this->pendingLangDomains) as $domain) {
            $this->translator->loadDomain(LANG_DIR, $domain);
        }

        $this->pendingLangDomains = [];
    }

    /**
     * Injector helper hook. Signature: (object $instance, string $fqcn)
     * Loads optional module assets:
     * - config: CONFIG_DIR/{Domain}.config.php
     * - lang:   LANG_DIR/{locale}/{Domain}.{locale}.php (or deferred until locale set)
     * Then applies config section to instance (attributes/interface).
     */
    public function onClassCreated(object $instance, string $fqcn)
    : void {
        $domain = (new \ReflectionClass($fqcn))->getShortName();

        // 1) config file (optional)
        $this->config->loadModuleConfig($domain);

        // 2) apply config section to instance (optional)
        $this->config->applyModuleConfig($instance, $domain);

        // 3) lang file (optional)
        if ($this->localeSet) {
            $this->translator->loadDomain(LANG_DIR, $domain);
        } else {
            $this->pendingLangDomains[$domain] = true;
        }
    }
}