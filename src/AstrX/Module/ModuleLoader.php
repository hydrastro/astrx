<?php
declare(strict_types=1);

namespace AstrX\Module;

use AstrX\Config\Config;
use AstrX\Config\ConfigDomainResolver;
use AstrX\Config\Diagnostic\ConfigFileInvalidDiagnostic;
use AstrX\Config\InjectConfig;
use AstrX\I18n\Translator;
use AstrX\I18n\TranslatorAwareInterface;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkAwareInterface;
use AstrX\Result\DiagnosticSinkInterface;
use ReflectionClass;
use ReflectionException;
use function AstrX\Support\langDir;

final class ModuleLoader
{
    private bool $localeSet = false;

    /** @var array<string, true> Domains whose language files should be loaded once the locale is known. */
    private array $pendingLangDomains = [];

    private ConfigDomainResolver $domains;

    /**
     * config_optional / lang_optional (config.php ['ModuleLoader']).
     *
     * Default true = today's behaviour: a class with no config file and a domain
     * with no language file are both silent. Set either false in development to
     * be told about a module whose config or lang file was never created — the
     * failure mode is a page that renders hardcoded defaults or raw translation
     * keys with nothing in the diagnostics explaining why.
     */
    private bool $configOptional = true;
    private bool $langOptional   = true;

    /**
     * @param DiagnosticSinkInterface|null $sink
     *   The shared sink handed to every class implementing
     *   DiagnosticSinkAwareInterface. Null leaves such classes on whatever sink
     *   they constructed themselves.
     * @param string $langDir
     *   Override the language directory. Defaults to the LANG_DIR constant when
     *   empty. Inject an explicit path in tests to avoid relying on global state.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Translator $translator,
        private readonly ?DiagnosticSinkInterface $sink = null,
        private string $langDir = '',
        ?ConfigDomainResolver $domains = null,
    ) {
        if ($this->langDir === '' && defined('LANG_DIR')) {
            $this->langDir = langDir();
        }
        $this->domains = $domains ?? new ConfigDomainResolver();
    }

    // -------------------------------------------------------------------------
    // Configuration (config.php ['ModuleLoader'])
    //
    // These four keys were inert before: Prelude hand-builds ModuleLoader, and
    // #[InjectConfig] wiring only ran from inside ModuleLoader's own injector
    // helper — which never fires for an object the injector did not create. So
    // ModuleLoader could never receive its own configuration. Prelude now
    // applies the section explicitly to each hand-built singleton, and these
    // setters give the keys somewhere to land.
    // -------------------------------------------------------------------------

    /**
     * Language directory override.
     *
     * Ignored unless it names an existing directory. config.php ships the
     * container's absolute path (/app/resources/lang/); a bare-metal, CI or
     * development checkout has no /app, and honouring a non-existent directory
     * would make Translator::loadDomain() find nothing for every domain — the
     * whole site would render raw translation keys ("WORDING_CONTENT") instead
     * of text. Falling back to the LANG_DIR constant keeps that install working.
     */
    #[InjectConfig('lang_dir')]
    public function setLangDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $this->langDir = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Config directory override, used when resolving which file declares a
     * section. Same existence guard, and for the same reason: pointed at a
     * directory that isn't there, every section lookup would come back empty and
     * every class would silently run on hardcoded defaults.
     *
     * Note this cannot relocate the config directory as a whole — the file that
     * declares this key is itself found through the CONFIG_DIR constant. It
     * retargets section resolution only.
     */
    #[InjectConfig('config_dir')]
    public function setConfigDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $this->domains = new ConfigDomainResolver(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR);
    }

    /** False → warn when a class with #[InjectConfig] setters has no config file. */
    #[InjectConfig('config_optional')]
    public function setConfigOptional(bool $optional): void
    {
        $this->configOptional = $optional;
    }

    /** False → warn when a domain has no language file in the active locale. */
    #[InjectConfig('lang_optional')]
    public function setLangOptional(bool $optional): void
    {
        $this->langOptional = $optional;
    }

    public function setLocale(string $locale): void
    {
        $this->translator->setLocale($locale);
        $this->localeSet = true;

        foreach (array_keys($this->pendingLangDomains) as $domain) {
            $this->loadLangDomain($domain);
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
            // The injector only calls this for a class it has just instantiated,
            // so reflection on it cannot realistically fail. Nothing useful to
            // configure without a name; leave the instance as constructed.
            return;
        }

        // Which section(s), in which file(s)? #[ConfigDomain] when the class
        // declares one; otherwise the historical convention — class short name,
        // plus the immediate parent namespace segment when no
        // {ClassName}.config.php exists (AstrX\Captcha\CaptchaRenderer →
        // Captcha.config.php; AstrX\Session\SecureSessionHandler ← the 'Session'
        // section). applyModuleConfig() no-ops on a missing section, so applying
        // every candidate injects the right one without touching classes whose
        // section already matches their name.
        $candidates = $this->domains->forClass($fqcn);

        // EVERY file first, THEN every section. A class's own section usually
        // lives in the PARENT's file (section 'ImapClient' is declared in
        // Mail.config.php), so applying section N before file N+1 has been
        // loaded would find nothing there and silently leave the instance on its
        // hardcoded defaults — for ImapClient that is "no SOCKS5 proxy", i.e.
        // IMAP off Tor.
        foreach ($candidates as $candidate) {
            $this->config->loadModuleConfig($candidate['file']);
        }

        $hasInjectConfig = $this->classHasInjectConfig($fqcn);
        foreach ($candidates as $i => $candidate) {
            $this->config->applyModuleConfig($instance, $candidate['section']);
            // Only check for unused config keys on classes that declare
            // #[InjectConfig] setters, and only for their PRIMARY section. Those
            // keys are resolved at construction time and can be checked
            // immediately. Classes that read config via getConfig() do so at
            // request-handling time — checking here would produce false
            // positives for every key they haven't read yet, and a shared
            // fallback section (Session, Captcha, Mail) is read by several
            // classes, so checking it from one of them flags the keys the OTHER
            // classes own. tools/check_config.php does the exhaustive sweep with
            // every consumer in view.
            if ($hasInjectConfig && $i === 0) {
                $this->config->emitUnusedKeyDiagnostics($candidate['section']);
            }
        }

        // config_optional=false: a class with #[InjectConfig] setters and no
        // config file anywhere runs entirely on hardcoded defaults. Opt-in
        // because most classes legitimately have no config file at all.
        if (!$this->configOptional && $hasInjectConfig) {
            $missing = null;
            foreach ($candidates as $candidate) {
                $path = $this->domains->configDir() . $candidate['file'] . '.config.php';
                if (is_file($path)) {
                    $missing = null;
                    break;
                }
                $missing ??= $path;
            }
            if ($missing !== null) {
                $this->emitResourceMissing($missing);
            }
        }

        // Wire translator if the instance opts in via TranslatorAwareInterface.
        // This replaces the previously dead TranslatorAwareInterface.
        if ($instance instanceof TranslatorAwareInterface) {
            $instance->setTranslator($this->translator);
        }

        // Same wiring for DiagnosticSinkAwareInterface. Without it TemplateEngine
        // — the only implementor — keeps the private DiagnosticsCollector its
        // constructor falls back to, so every diagnostic it emits OUTSIDE a
        // renderTemplate() call (missing template file, unreadable template file,
        // a template that throws during evaluation) is collected into an object
        // nothing ever reads: the page renders blank or half-built with no
        // message anywhere explaining why.
        if ($this->sink !== null && $instance instanceof DiagnosticSinkAwareInterface) {
            $instance->setDiagnosticSink($this->sink);
        }

        if ($this->localeSet) {
            $this->loadLangDomain($domain);
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

    /** Load a domain's catalog, reporting a missing file when lang_optional=false. */
    private function loadLangDomain(string $domain): void
    {
        $this->translator->loadDomain($this->langDir, $domain);

        if ($this->langOptional) {
            return;
        }

        $locale = $this->translator->getLocale();
        $base   = rtrim($this->langDir, '/\\') . DIRECTORY_SEPARATOR . $locale
                . DIRECTORY_SEPARATOR . $domain;
        if (is_file($base . '.' . $locale . '.php') || is_file($base . '.php') || is_dir($base)) {
            return;
        }

        $this->emitResourceMissing($base . '.' . $locale . '.php');
    }

    /**
     * Report a missing OPTIONAL config/lang resource. Only reachable with
     * config_optional / lang_optional turned off; the path's suffix says which
     * kind it is.
     */
    private function emitResourceMissing(string $path): void
    {
        $this->sink?->emit(new ConfigFileInvalidDiagnostic(
            id:    'astrx.config/resource_missing',
            level: DiagnosticLevel::WARNING,
            file:  $path,
        ));
    }
}
