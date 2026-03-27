<?php
declare(strict_types=1);

namespace AstrX\Module;

use AstrX\Config\Config;
use AstrX\Config\InjectConfig;
use AstrX\I18n\Translator;
use AstrX\I18n\TranslatorAwareInterface;
use ReflectionClass;
use ReflectionException;
use function AstrX\Support\configDir;
use function AstrX\Support\langDir;

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
            $this->langDir = langDir();
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
            /** @var class-string $fqcn */
            $ref    = new ReflectionClass($fqcn);
            $domain = $ref->getShortName();
        } catch (ReflectionException) {
            // If reflection fails (should never happen for a just-created class),
            // skip silently — we have no diagnostic sink here.
            return;
        }

        $this->config->loadModuleConfig($domain);

        // If no dedicated {ClassName}.config.php exists, fall back to a shared
        // config file named after the immediate parent namespace segment.
        // Example: AstrX\Captcha\CaptchaRenderer → try Captcha.config.php
        // This allows grouping related classes (CaptchaRenderer, CaptchaService)
        // under a single config file without touching ContentManager or adding
        // per-class annotations.
        $configDir = configDir();
        if (!file_exists($configDir . $domain . '.config.php')) {
            $namespaceParts = explode('\\', $fqcn);
            // [0]=AstrX [1]=Captcha [2]=CaptchaRenderer → parent = 'Captcha'
            $parentCount = count($namespaceParts);
            if ($parentCount >= 3) {
                $parentDomain = $namespaceParts[$parentCount - 2];
                if ($parentDomain !== $domain) {
                    $this->config->loadModuleConfig($parentDomain);
                }
            }
        }

        $this->config->applyModuleConfig($instance, $domain);
        // Only check for unused config keys on classes that declare
        // #[InjectConfig] setters. Those keys are resolved at construction
        // time and can be checked immediately. Classes that read config via
        // getConfig() do so at request-handling time — checking here would
        // produce false positives for every key they haven't read yet.
        if ($this->classHasInjectConfig($fqcn)) {
            $this->config->emitUnusedKeyDiagnostics($domain);
        }

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

    /**
     * Returns true if the class has at least one method with an
     * #[InjectConfig] attribute. When true, all config keys for the
     * class's domain are resolved at construction time and we can
     * reliably detect unused ones immediately after injection.
     */
    private function classHasInjectConfig(string $fqcn): bool
    {
        try {
            /** @phpstan-ignore argument.type */
            $rc = new \ReflectionClass($fqcn);
        } catch (\ReflectionException) {
            return false;
        }
        foreach ($rc->getMethods() as $method) {
            if ($method->getAttributes(InjectConfig::class) !== []) {
                return true;
            }
        }
        return false;
    }
}
