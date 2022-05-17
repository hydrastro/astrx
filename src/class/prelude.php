<?php

/**
 * Class Prelude.
 */
class Prelude
{
    /**
     * @var array<int, array<mixed>> $messages Messages array.
     */
    public array $messages = array();
    /**
     * @var array<int, Throwable> $exceptions Exceptions objects array.
     */
    public array $exceptions = array();

    /**
     * Prelude Constructor.
     */
    public function __construct()
    {
        // Loading core classes.
        $ErrorHandler = new ErrorHandler();
        $config = new Config();

        // Now we can relax. We have a custom error handler in english.

        // Wiring together the Error Handler.
        $ErrorHandler->addClass($this);
        $ErrorHandler->addClass($config);

        // Setting up environment.
        // $environment = $config->getConfig("environment");
        // $ErrorHandler->setEnvironment($environment);

        $config->loadLang("injector");
        $config->loadConfig("injector");
        $injector = new Injector();

        // Configuring the injector to load config and auto-wire stuff.
        $injector->addHelper($ErrorHandler, "addClass");
        $injector->addHelper($config, "loadLang");
        $injector->addHelper($config, "loadConfig");
        $injector->addHelper($config, "configurationMethodsHelper");

        // TODO: change ALL the errors/exceptions. fif new Exception

        // Adding existing classes to the injector container.
        $injector->setClass($config);
        $injector->setClass($ErrorHandler);
        $injector->setClass($this);
        $ErrorHandler->addClass($injector);

        // Creating database connection.
        $dsn = $config->getConfig("db_type", "PDO");
        $host = $config->getConfig("db_host", "PDO");
        $dbname = $config->getConfig("db_name", "PDO");
        $passwd = $config->getConfig(
            "db_password",
            "PDO"
        );
        if (!is_string($dsn) ||
            !is_string($host) ||
            !is_string($dbname) ||
            !is_string($passwd)) {
            // TODO set error
            return;
        }
        $username = $config->getConfig(
            "db_username",
            "PDO"
        );
        $injector->setClassArgs(
            "PDO",
            array(
                "dsn" => $dsn . ":host=" . $host . ";dbname=" . $dbname . ";",
                "username" => $username,
                "password" => $passwd
            )
        );
        try {
            /**
             * @var PDO $pdo PDO.
             */
            $pdo = $injector->createClass("PDO");
        } catch (PDOException $e) {
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => ERROR_CLASS_PDO . $e->getMessage()
            );

            return;
        }
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        // Finally creating the Content Manager class.
        /**
         * @var ContentManager $cms Content Manager.
         */
        $cms = $injector->getClass("ContentManager");
        $cms->init();
    }
}
