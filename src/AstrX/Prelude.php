<?php
declare(strict_types=1);

namespace AstrX;

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
            $config->getConfig(
                'Prelude',
                'environment',
                EnvironmentType::DEVELOPMENT->value
            )
        );
        assert($env instanceof EnvironmentType);
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
