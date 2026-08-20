<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Auth\Gate;
use AstrX\Auth\GateBootstrapper;
use AstrX\Config\Config;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleLoader;
use AstrX\Result\DiagnosticsCollector;
use AstrX\ErrorHandler\EnvironmentType;
use AstrX\ErrorHandler\ErrorHandler;
use AstrX\ContentManager;

final class Prelude
{
    public function __construct()
    {
        // Force UTC for every date()/timestamp render, independent of the host's
        // php.ini date.timezone / TZ. On a Tor hidden service the ambient server
        // timezone is a deanonymising signal (it stamps the operator's region onto
        // every post/comment/chat time); pinning UTC here removes that leak the
        // same way the feeds already use gmdate().
        date_default_timezone_set('UTC');

        $collector = new DiagnosticsCollector();

        $errorHandler = new ErrorHandler($collector);

        $config = new Config($collector);

        // Environment setup — must happen before anything else so that PHP error
        // reporting and assert behaviour are configured for the right environment.
        $env = EnvironmentType::from(
            $config->getConfigInt(
                'Prelude',
                'environment',
                EnvironmentType::PRODUCTION->value
            )
        );
        $errorHandler->setEnvironment($env);

        $translator = new Translator($collector);

        $moduleLoader = new ModuleLoader($config, $translator, $collector);

        $injector = new Injector();

        // These five are hand-built, so the injector never creates them and
        // ModuleLoader::onClassCreated — where #[InjectConfig] wiring happens —
        // never fires for them. Without this loop the framework's own
        // configuration is structurally inert: every key under Prelude,
        // ModuleLoader, ErrorHandler, Injector and Translator can be edited,
        // validated and persisted by the admin UI and still reach nothing.
        // applyModuleConfig() no-ops when a section or a setter is absent, so
        // this is exact: it only ever delivers keys that have a setter.
        $config->loadModuleConfig('Translator');
        foreach ([
            [$config,        'Config'],
            [$translator,    'Translator'],
            [$moduleLoader,  'ModuleLoader'],
            [$injector,      'Injector'],
            [$errorHandler,  'ErrorHandler'],
        ] as [$instance, $domain]) {
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
        $injector->setClass($errorHandler);
        $injector->setClass($config);
        $injector->setClass($translator);
        $injector->setClass($moduleLoader);
        $injector->setClass($injector);
        $injector->setClass($this);

        // Bootstrap PBAC Gate — register all policies once at startup.
        // The Gate itself is auto-wired by the Injector when first requested.
        // We create it explicitly here so policies are registered before any
        // controller runs. Failure is non-fatal (Gate falls back to deny-all).
        $gateResult = $injector->createClass(Gate::class)->drainTo($collector);
        if ($gateResult->isOk()) {
            $gate = $gateResult->unwrap();
            assert($gate instanceof Gate);
            $bootstrapResult = $injector->createClass(GateBootstrapper::class)
                ->drainTo($collector);
            if ($bootstrapResult->isOk()) {
                /** @var \AstrX\Auth\GateBootstrapper $bootstrapper */
                $bootstrapper = $bootstrapResult->unwrap();
                $bootstrapper->registerAll($gate);
            }
            $injector->setClass($gate);
        }

        // Create ContentManager — guard unwrap() so a missing dependency produces
        // a clear RuntimeException (caught by ErrorHandler) rather than a generic
        // "called unwrap() on a failed Result" LogicException with no context.
        $cmResult = $injector->createClass(ContentManager::class)
            ->drainTo($collector);

        if (!$cmResult->isOk()) {
            throw new \RuntimeException('Failed to create ContentManager — check diagnostics.');
        }

        $cm = $cmResult->unwrap();
        assert($cm instanceof ContentManager);
        $cm->init();
    }
}
