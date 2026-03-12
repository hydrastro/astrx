<?php
declare(strict_types=1);

namespace AstrX\Module;

use AstrX\Config\Config;
use AstrX\I18n\Translator;
use ReflectionClass;
use ReflectionException;

final class ModuleLoader
{
    private bool $localeSet = false;

    /** @var array<string, true> Domains whose language files should be loaded once the locale is known. */
    private array $pendingLangDomains = [];

    public function __construct(
        private readonly Config $config,
        private readonly Translator $translator,
    ) {}

    public function setLocale(string $locale): void
    {
        $this->translator->setLocale($locale);
        $this->localeSet = true;

        foreach (array_keys($this->pendingLangDomains) as $domain) {
            $this->translator->loadDomain(LANG_DIR, $domain);
        }

        $this->pendingLangDomains = [];
    }

    /**
     * Injector helper hook: called for every class the injector creates.
     *
     * Signature must match helper contract: (object $instance, string $fqcn): void
     */
    public function onClassCreated(object $instance, string $fqcn): void
    {
        try {
            $domain = (new ReflectionClass($fqcn))->getShortName();
        } catch (ReflectionException) {
            // If reflection fails (should never happen for a just-created class),
            // skip silently – we have no diagnostic sink here.
            return;
        }

        $this->config->loadModuleConfig($domain);
        $this->config->applyModuleConfig($instance, $domain);

        if ($this->localeSet) {
            $this->translator->loadDomain(LANG_DIR, $domain);
        } else {
            $this->pendingLangDomains[$domain] = true;
        }
    }
}
