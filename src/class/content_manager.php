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
    public $template_args = array();

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
        $TemplateEngine = $this->injector->createClass("TemplateEngine");
        /**
         * @var TemplateEngine $TemplateEngine Template Engine.
         */
        $template = $TemplateEngine->loadTemplate("template");

        $this->response->setContent($template->render($this->template_args));
        $this->response->send();
    }
}
