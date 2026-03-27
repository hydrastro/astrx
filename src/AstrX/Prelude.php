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

final class Prelude
{
    public function __construct()
    {
        $collector = new DiagnosticsCollector();

        $errorHandler = new ErrorHandler($collector);

        $config = new Config($collector);

        // Environment setup — must happen before anything else so that PHP error
        // reporting and assert behaviour are configured for the right environment.
        $env = EnvironmentType::from(
            $config->getConfigInt(
                'Prelude',
                'environment',
                EnvironmentType::DEVELOPMENT->value
            )
        );
        $errorHandler->setEnvironment($env);

        $translator = new Translator($collector);

        $moduleLoader = new ModuleLoader($config, $translator);

        $injector = new Injector();

        // Register helper: load module assets on class creation
        $injector->addHelper($moduleLoader, 'onClassCreated')->drainTo($collector);

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
