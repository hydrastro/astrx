<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Config\Config;
use AstrX\Config\ConfigDomainResolver;
use AstrX\ErrorHandler\EnvironmentType;
use AstrX\ErrorHandler\ErrorHandler;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleLoader;
use AstrX\Module\ModuleRegistry;
use AstrX\Result\DiagnosticsCollector;

/**
 * The application's services, wired: configuration (the site's files over the
 * installed modules' defaults), translations, the module loader that gives
 * every injector-built class its config section and catalog, and the
 * installed-module registry.
 *
 * Prelude builds it for a web request and adds the request's own pieces
 * (error page handling, the session and Gate, ContentManager). A CLI tool
 * builds it too, so a cron job sees exactly the configuration, modules and
 * module extensions the site does — instead of re-creating some of them by
 * hand and getting a config section's name wrong.
 *
 * Starts no session and sends no output. The database is not connected here:
 * the caller registers a PDO when it has one (Database\ConnectionFactory).
 */
final class ApplicationContainer
{
    /**
     * @param ErrorHandler|null $errorHandler the web request's handler: it is
     *        put into the configured environment, given its config section and
     *        shared. A CLI tool passes none.
     */
    public static function build(DiagnosticsCollector $collector, ?ErrorHandler $errorHandler = null): Injector
    {
        // Where the installed modules keep their templates, translations and
        // default config. Needed before Config itself, whose module sections
        // default from those files; the site's resources/ overrides all of them.
        $roots = ModuleRegistry::resourceRootsFor(ApplicationModules::INSTALLED);

        $config = new Config($collector, null, $roots);

        // Environment setup — must happen before anything else so that PHP error
        // reporting and assert behaviour are configured for the right environment.
        $errorHandler?->setEnvironment(EnvironmentType::from(
            $config->getConfigInt(
                'Prelude',
                'environment',
                EnvironmentType::PRODUCTION->value
            )
        ));

        $translator = new Translator($collector, $roots);

        $configDomains = new ConfigDomainResolver('', $roots);
        $moduleLoader  = new ModuleLoader($config, $translator, $collector, '', $configDomains);

        $injector = new Injector();

        // These are hand-built, so the injector never creates them and
        // ModuleLoader::onClassCreated — where #[InjectConfig] wiring happens —
        // never fires for them. Without this loop the framework's own
        // configuration is structurally inert: every key under Prelude,
        // ModuleLoader, ErrorHandler, Injector and Translator can be edited,
        // validated and persisted by the admin UI and still reach nothing.
        // applyModuleConfig() no-ops when a section or a setter is absent, so
        // this is exact: it only ever delivers keys that have a setter.
        $config->loadModuleConfig('Translator');
        $handBuilt = [
            [$config,        'Config'],
            [$translator,    'Translator'],
            [$moduleLoader,  'ModuleLoader'],
            [$injector,      'Injector'],
        ];
        if ($errorHandler !== null) {
            $handBuilt[] = [$errorHandler, 'ErrorHandler'];
        }
        foreach ($handBuilt as [$instance, $domain]) {
            $config->applyModuleConfig($instance, $domain);
        }

        // Register helper: load module assets on class creation.
        // This is the one Result in the composition root that MUST be checked:
        // if the helper does not register, no class the injector builds ever
        // receives #[InjectConfig] wiring, its translations or its diagnostic
        // sink. The whole application then runs on hardcoded defaults — no
        // SOCKS5 proxy, no server secret, no configured limits — and every page
        // still renders 200, so nothing anywhere says why.
        $helperResult = $injector->addHelper($moduleLoader, 'onClassCreated')->drainTo($collector);
        if (!$helperResult->isOk()) {
            throw new \RuntimeException(
                'Failed to register the ModuleLoader injector helper — no class would receive '
                . 'its configuration, translations or diagnostic sink. Check diagnostics.'
            );
        }

        // Register shared instances
        $injector->setClass($collector);
        $injector->setClass($roots);
        $injector->setClass($configDomains);
        if ($errorHandler !== null) {
            $injector->setClass($errorHandler);
        }
        $injector->setClass($config);
        $injector->setClass($translator);
        $injector->setClass($moduleLoader);
        $injector->setClass($injector);

        // The installed modules, explicitly: the registry is built here, never
        // autowired, so no part of the application can get one that silently
        // knows about a different (or empty) set of modules.
        $injector->setClass(new ModuleRegistry($config, $injector, ApplicationModules::INSTALLED));

        return $injector;
    }
}
