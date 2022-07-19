<?php

declare(strict_types = 1);
/**
 * Class ContentManager.
 */
class ContentManager
{
    /**
     * @var array<int, array<int, mixed>> $results Results array.
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
     * @param Config   $config   Config.
     * @param Injector $injector Injector.
     * @param PDO      $pdo      PDO.
     */
    public function __construct(Config $config, Injector $injector, PDO $pdo)
    {
        $this->config = $config;
        $this->injector = $injector;
        $this->pdo = $pdo;
    }

    /**
     * Init.
     * Init function.
     * @return void
     */
    public function init()
    : void
    {
        echo "<h1>AstrX</h1>";

        $lang = "en";
        if (!$this->config->setLangAndLoadDeferred($lang)) {
            // peacefully die
            return;
        }
    }
}
