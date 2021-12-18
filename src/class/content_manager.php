<?php

class ContentManager
{
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
     * @param Config   $config
     * @param Injector $injector
     */
    public function __construct(Config $config, Injector $injector)
    {
        $injector->setClassArgs(
            "PDO",
            array(
                "dsn" => $config->getConfig("db_type", "PDO") .
                         ":host=" .
                         $config->getConfig("db_host", "PDO") .
                         ";dbname=" .
                         $config->getConfig("db_name", "PDO") .
                         ";",
                "username" => $config->getConfig(
                    "db_username",
                    "PDO"
                ),
                "passwd" => $config->getConfig(
                    "db_password",
                    "PDO"
                )
            )
        );
        $pdo = $injector->createClass("PDO");
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );
        $this->pdo = $pdo;
        $this->config = $config;
        $this->injector = $injector;
    }
}
