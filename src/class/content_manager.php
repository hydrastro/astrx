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
     * @var Config $config Config.
     */
    private Config $config;
    /**
     * @var Injector $injector Injector.
     */
    private Injector $injector;
    /**
     * @var PDO $pdo PDO.
     */
    private PDO $pdo;
    /**
     * @var Response $response Response.
     */
    private Response $response;
    /**
     * @var ErrorHandler $ErrorHandler Error Handler.
     */
    private ErrorHandler $ErrorHandler;
    /**
     * @var array<string, mixed> $template_args Template interpolation
     * arguments.
     */
    public array $template_args = array();

    /**
     * ContentManager Constructor.
     *
     * @param ErrorHandler $ErrorHandler Error Handler.
     * @param Config       $config       Config.
     * @param Injector     $injector     Injector.
     * @param PDO          $pdo          PDO.
     * @param Response     $response     Response.
     */
    public function __construct(
        ErrorHandler $ErrorHandler,
        Config $config,
        Injector $injector,
        PDO $pdo,
        Response $response
    ) {
        $this->ErrorHandler
            = $ErrorHandler;
        $this->config
            = $config;
        $this->injector
            = $injector;
        $this->pdo
            = $pdo;
        $this->response
            = $response;
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

        // Now that the language files and all the bootstrap components have
        // been loaded we can inject the results maps into the error handler
        // so we have a way nicer error/results display.
        $this->ErrorHandler->addMultipleResultsMaps($this->getInitResultsMap());

        $TemplateEngine = $this->injector->createClass("TemplateEngine");
        /**
         * @var TemplateEngine $TemplateEngine Template Engine.
         */
        $template = $TemplateEngine->loadTemplate("template");

        // @phpstan-ignore-next-line
        $this->response->setContent($template->render($this->template_args));
        $this->response->send();
    }

    /**
     * Get Init Results Map.
     * Returns the result map of the core components and the other bootstrap
     * components.
     * @return array<string, array<int, array<int, mixed>>>
     */
    private function getInitResultsMap()
    : array
    {
        return array(
            "Injector" => array(
                Injector::ERROR_HELPER_METHOD_NOT_FOUND => array(
                    500,
                    ERROR_HELPER_METHOD_NOT_FOUND,  // class_name, method_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Injector::ERROR_INVALID_HELPER_METHOD => array(
                    500,
                    ERROR_INVALID_HELPER_METHOD,  // class_name, method_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Injector::ERROR_HELPER_REFLECTION => array(
                    500,
                    ERROR_METHOD_REFLECTION, // class_name, method_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Injector::ERROR_CLASS_NOT_FOUND => array(
                    500,
                    ERROR_CLASS_NOT_FOUND, // class_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Injector::ERROR_CLASS_METHOD_NOT_FOUND => array(
                    500,
                    ERROR_CLASS_METHOD_NOT_FOUND, // class_name, method_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Injector::ERROR_CLASS_NOT_FOUND_2 => array(
                    500,
                    ERROR_CLASS_NOT_FOUND, // class_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Injector::ERROR_CLASS_NOT_FOUND_3 => array(
                    500,
                    ERROR_CLASS_NOT_FOUND, // class_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Injector::ERROR_CLASS_OR_PARAMETER_NOT_FOUND => array(
                    500,
                    ERROR_CLASS_OR_PARAMETER_NOT_FOUND, // class_name,
                    // parameter_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Injector::ERROR_CLASS_REFLECTION => array(
                    500,
                    ERROR_CLASS_REFLECTION, // message
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Injector::ERROR_REFLECTION_PARAMETER => array(
                    500,
                    ERROR_REFLECTION_PARAMETER, // class_name, parameter_name
                    ErrorHandler::LOG_LEVEL_ERROR
                )
            ),
            "Config" => array(
                Config::ERROR_CONFIG_NOT_FOUND => array(
                    500,
                    ERROR_CONFIG_NOT_FOUND, // class_name, config_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Config::ERROR_INVALID_LANGUAGE => array(
                    500,
                    ERROR_INVALID_LANGUAGE,
                    ErrorHandler::LOG_LEVEL_ERROR
                )
            ),
            "Prelude" => array(
                Prelude::ERROR_PDO_EXCEPTION => array(
                    500,
                    ERROR_PDO_EXCEPTION, // message
                    ErrorHandler::LOG_LEVEL_ERROR
                )
            ),
            "ErrorHandler" => array(
                ErrorHandler::ERROR_CLASS_TO_REMOVE_NOT_FOUND => array(
                    500,
                    ERROR_CLASS_TO_REMOVE_NOT_FOUND, // class_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                ErrorHandler::ERROR_UNDEFINED_ENVIRONMENT => array(
                    500,
                    ERROR_UNDEFINED_ENVIRONMENT,
                    ErrorHandler::LOG_LEVEL_ERROR
                )
            ),
            "Response" => array(
                Response::ERROR_INVALID_HTTP_STATUS_CODE => array(
                    500,
                    ERROR_INVALID_HTTP_STATUS_CODE, // status_code
                    ErrorHandler::LOG_LEVEL_ERROR
                )
            )
        );
    }
}
