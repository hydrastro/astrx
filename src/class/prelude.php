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
        $ErrorHandler = new ErrorHandler();
        $config = new Config();
        $MessageHandler = new MessageHandler();
        $ErrorHandler->addClass($config);
        $MessageHandler->addClass($config);
        $ErrorHandler->addClass($MessageHandler);
        $MessageHandler->addClass($ErrorHandler);
        $ErrorHandler->addClass($this);
        $MessageHandler->addClass($this);
        require(LANG_DIR . "injector.en.php");
        $injector = new Injector();
        $config->loadConfig("injector");
        $injector->setClass($config);
        $injector->setClass($ErrorHandler);
        $injector->setClass($MessageHandler);
        $injector->setClass($this);
        $injector->setConfig($config);
        $injector->setErrorHandler($ErrorHandler);
        $injector->setMessageHandler($MessageHandler);
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
    }
}
