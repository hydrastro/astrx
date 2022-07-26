<?php

declare(strict_types = 1);
/**
 * Class Prelude.
 */
class Prelude
{
    public const ERROR_PDO_EXCEPTION = 0;
    /**
     * @var array<int, array<int, mixed>> $results Results array.
     */
    public array $results = array();

    /**
     * Prelude Constructor.
     */
    public function __construct()
    {
        // Loading core classes.
        $ErrorHandler = new ErrorHandler();
        $config = new Config($ErrorHandler);

        // Now we can relax. We have a custom error handler.

        // Language loading is deferred for the core classes
        $config->addDeferredLangClass($config);
        $config->addDeferredLangClass($ErrorHandler);
        $config->addDeferredLangClass($this);

        // Wiring together the Error Handler.
        $ErrorHandler->addClass($this);
        $ErrorHandler->addClass($config);

        // Setting up environment.
        $environment = $config->getConfig(
            "Prelude",
            "environment",
            $ErrorHandler::ENVIRONMENT_DEVELOPMENT
        );
        // @phpstan-ignore-next-line
        $ErrorHandler->setEnvironment($environment);

        $injector = new Injector();
        $config->addDeferredLangClass($injector);

        // Configuring the injector to load config and auto-wire components.
        $injector->addHelper($ErrorHandler, "addClass");
        $injector->addHelper($config, "loadClassLangAndConfig");
        $injector->addHelper($config, "configurationMethodsHelper");

        // Adding existing classes to the injector container.
        $injector->setClass($config);
        $injector->setClass($ErrorHandler);
        $injector->setClass($this);
        $ErrorHandler->addClass($injector);

        // Finally creating the Content Manager class.
        $ContentManager = $injector->createClass("ContentManager");
        /**
         * @var ContentManager $ContentManager Content Manager.
         */
        /** @noinspection PhpUnhandledExceptionInspection */
        $ContentManager->init();

        // If the creation of PDO or ContentManager fails there will be an
        // exception thrown and the script will fall back to the ErrorManager.
    }
}
