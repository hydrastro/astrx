<?php

/**
 * Class ContentManager
 */
class ContentManager
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
     * @var Config $config
     */
    private Config $config;
    /**
     * @var Injector $injector
     */
    private Injector $injector;
    /**
     * @var PDO $pdo
     */
    private PDO $pdo;

    /**
     * ContentManager constructor.
     *
     * @param Config   $config   Config.
     * @param Injector $injector Injector.
     */
    public function __construct(Config $config, Injector $injector)
    {
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
                "passwd" => $passwd
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
        $this->pdo = $pdo;
        $this->config = $config;
        $this->injector = $injector;
    }

    /**
     * Foo
     * @return void
     */
    public function foo()
    {
        $x = array();
        $x[] = $this->config->exceptions;
        $x[] = $this->pdo->errorCode();
        $x[] = $this->injector->exceptions;
    }
}
