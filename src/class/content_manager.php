<?php

/**
 * Class ContentManager.
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
     * ContentManager Constructor.
     *
     * @param Config   $config   Config.
     * @param Injector $injector Injector.
     */
    public function __construct(PDO $pdo, Config $config, Injector $injector)
    {
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
