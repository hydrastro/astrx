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
    private Response $response;

    /**
     * ContentManager Constructor.
     *
     * @param Config   $config   Config.
     * @param Injector $injector Injector.
     * @param PDO      $pdo      PDO.
     */
    public function __construct(
        Config $config,
        Injector $injector,
        PDO $pdo,
        Response $response
    ) {
        $this->config = $config;
        $this->injector = $injector;
        $this->pdo = $pdo;
        $this->response = $response;
    }

    /**
     * Init.
     * Init function.
     * @return void
     */
    public function init()
    : void
    {
        $lang = "en";
        if (!$this->config->setLangAndLoadDeferred($lang)) {
            // peacefully die
            return;
        }

        $this->response->setContent("<h1>AstrX</h1>");
        $this->response->send();
    }
}
