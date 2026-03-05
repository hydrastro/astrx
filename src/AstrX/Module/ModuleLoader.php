<?php

declare(strict_types = 1);

namespace AstrX\Module;

use AstrX\Config\Config;
use AstrX\I18n\Translator;
use ReflectionClass;
use ReflectionException;

final class ModuleLoader
{
    private bool $localeSet = false;
    /** @var array<string,true> */
    private array $pendingLangDomains = [];

    public function __construct(
        private readonly Config $config,
        private readonly Translator $translator
    ) {
    }

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
     * Injector helper hook: called for each created class.
     */
    public function onClassCreated(object $instance, string $fqcn)
    : void {
         $domain = (new ReflectionClass($fqcn))->getShortName();

        // config is always loadable
        $this->config->loadModuleConfig($domain);
        $this->config->applyModuleConfig($instance, $domain);

        // lang may be deferred
        if ($this->localeSet) {
            $this->translator->loadDomain(LANG_DIR, $domain);
        } else {
            $this->pendingLangDomains[$domain] = true;
        }
    }
}