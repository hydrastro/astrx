<?php
declare(strict_types=1);

namespace AstrX\Module;

use AstrX\Config\Config;
use AstrX\I18n\Translator;
use AstrX\I18n\TranslatorAwareInterface;
use ReflectionClass;
use ReflectionException;

final class ModuleLoader
{
    private bool $localeSet = false;

    /** @var array<string, true> Domains whose language files should be loaded once the locale is known. */
    private array $pendingLangDomains = [];

    /**
     * @param string $langDir
     *   Override the language directory. Defaults to the LANG_DIR constant when
     *   empty. Inject an explicit path in tests to avoid relying on global state.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Translator $translator,
        private string $langDir = '',
    ) {
        if ($this->langDir === '' && defined('LANG_DIR')) {
            $this->langDir = LANG_DIR;
        }
    }

    public function setLocale(string $locale): void
    {
        $this->translator->setLocale($locale);
        $this->localeSet = true;

        foreach (array_keys($this->pendingLangDomains) as $domain) {
            $this->translator->loadDomain($this->langDir, $domain);
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
            // skip silently — we have no diagnostic sink here.
            return;
        }

        $this->config->loadModuleConfig($domain);
        $this->config->applyModuleConfig($instance, $domain);

        // Wire translator if the instance opts in via TranslatorAwareInterface.
        // This replaces the previously dead TranslatorAwareInterface.
        if ($instance instanceof TranslatorAwareInterface) {
            $instance->setTranslator($this->translator);
        }

        if ($this->localeSet) {
            $this->translator->loadDomain($this->langDir, $domain);
        } else {
            $this->pendingLangDomains[$domain] = true;
        }
    }
}
