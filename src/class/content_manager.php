<?php

declare(strict_types = 1);
/**
 * Class ContentManager.
 */
class ContentManager
{
    /**
     * @var array<int, array> $results Results array.
     */
    public array $results = array();
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
     * @param PDO      $pdo      PDO.
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
     * Init
     * @return void
     */
    public function init()
    {
        echo "<h1>AstrX</h1>";
    }
}
