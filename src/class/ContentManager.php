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
     * @var ErrorHandler $ErrorHandler Error Handler.
     */
    private ErrorHandler $ErrorHandler;
    /**
     * @var array<string, mixed> $template_args Template interpolation
     * arguments.
     */
    public array $template_args = array();
    /**
     * @var array<int, string> $current_page_parameters Current page
     * parameters.
     */
    private array $current_page_parameters = array();
    /**
     * @var int $current_route Current route number.
     */
    private int $current_route = 0;

    /**
     * ContentManager Constructor.
     *
     * @param ErrorHandler $ErrorHandler Error Handler.
     * @param Config       $config       Config.
     * @param Injector     $injector     Injector.
     */
    public function __construct(
        ErrorHandler $ErrorHandler,
        Config $config,
        Injector $injector
    ) {
        $this->ErrorHandler = $ErrorHandler;
        $this->config = $config;
        $this->injector = $injector;
    }

    /**
     * Init.
     * Init function.
     * @return void
     * @throws Exception
     */
    public function init()
    : void
    {
        // Setting the current page parameters if url rewrite is enabled.
        $url_rewrite = $this->config->getConfig(
            "ContentManager",
            "url_rewrite",
            false
        );
        if ($url_rewrite) {
            $parameters_config = $this->config->getConfig(
                "ContentManager", "current_page_parameters_config", array()
            );
            // @phpstan-ignore-next-line
            foreach ($parameters_config as $parameter_config) {
                $parameter_name = $this->config->getConfig(
                    "ContentManager",
                    // @phpstan-ignore-next-line
                    $parameter_config,
                    ""
                );
                // @phpstan-ignore-next-line
                $this->setCurrentPageParameters(array($parameter_name));
            }
        }

        // Setting the current language.
        $request = $this->injector->createClass("Request");
        /**
         * @var Request $request Request.
         */
        $language_parameter_name = $this->config->getConfig(
            "ContentManager",
            "language_parameter_name"
        );
        $lang = "";
        if ($language_parameter_name !== null) {
            // @phpstan-ignore-next-line
            $lang = $request->get($language_parameter_name, "");
        }
        // @phpstan-ignore-next-line
        $this->config->setLang($lang);

        // Now that the language files and all the bootstrap components have
        // been loaded we can inject the results maps into the error handler,
        // so we have a way nicer error/results display.
        $this->ErrorHandler->addMultipleResultsMaps($this->getInitResultsMap());

        // We can now start building our response.
        $TemplateEngine = $this->injector->createClass("TemplateEngine");
        $response = $this->injector->createClass("Response");

        // Setting the current page id.
        $current_page_id = $request->get(
        // @phpstan-ignore-next-line
            $this->config->getConfig(
                "ContentManager",
                "page_id_parameter_name",
                ""
            ),
            $this->config->getConfig("ContentManager", "main_page_id", "")
        );

        // Creating database connection.
        // $config->loadConfig("PDO"); It's a built in class so its config
        // will just be loaded along with the main configs.
        $dsn = $this->config->getConfig("PDO", "db_type", "");
        $host = $this->config->getConfig("PDO", "db_host", "");
        $dbname = $this->config->getConfig("PDO", "db_name", "");
        $passwd = $this->config->getConfig("PDO", "db_password", "");
        $username = $this->config->getConfig("PDO", "db_username", "");
        $this->injector->setClassArgs(
            "PDO", array(
                     "dsn" => $dsn .
                              ":host=" .
                              $host .
                              ";dbname=" .
                              $dbname .
                              ";",
                     "username" => $username,
                     "password" => $passwd
                 )
        );
        $pdo = $this->injector->createClass("PDO");
        /**
         * @var PDO $pdo PDO.
         */
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $PageHandler = $this->injector->createClass("PageHandler");
        /**
         * @var PageHandler $PageHandler Page handler.
         */
        // @phpstan-ignore-next-line
        $current_page = $PageHandler->getPage($current_page_id);
        if ($current_page === null || $current_page->hidden) {
            http_response_code(404);
            $current_page = $PageHandler->getErrorPage();
        }

        // Calls to controllers.
        // Controllers can either build a response themselves and send it
        // or alternatively they can fall back here to default template.
        // Controllers can edit the default template arguments;
        // $this->template_args is public.
        if ($current_page->controller) {
            $controller_name = $this->getControllerName(
                $current_page->file_name
            );
            $controller = $this->injector->getClass($controller_name);
            $controller->init();
        }

        /**
         * @var TemplateEngine $TemplateEngine Template Engine.
         */
        $template = $TemplateEngine->loadTemplate("template");
        $this->template_args["title"] = $current_page->title;
        $this->template_args["description"] = $current_page->description;
        $this->template_args["keywords"] = implode(
            ", ",
            $current_page->keywords
        );
        $this->template_args["index"] = $current_page->index;
        $this->template_args["follow"] = $current_page->follow;
        $this->template_args["content"] = $current_page->file_name;
        $this->template_args["time"] = round(
            (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
            4
        );
        /**
         * @var Response $response Response.
         */
        // @phpstan-ignore-next-line
        $response->setContent($template->render($this->template_args));
        $response->send();
    }

    /**
     * Get Controller Name.
     * Returns the class name of a page controller.
     *
     * @param string $file_name
     *
     * @return string
     */
    public function getControllerName(string $file_name)
    : string {
        return str_replace(
                   '_',
                   '',
                   ucwords(
                       $file_name,
                       '_'
                   )
               ) . "Controller";
    }

    /**
     * Set Current Page Parameters.
     * Sets the parameters for the current page.
     *
     * @param array<int, string> $parameters Page parameters.
     * @param bool               $append     Append flag.
     *
     * @return void
     */
    public function setCurrentPageParameters(
        array $parameters,
        bool $append
        = true
    )
    : void {
        if ($append) {
            $this->current_page_parameters = array_merge(
                $this->current_page_parameters,
                $parameters
            );
            $this->current_route += count($parameters);
        } else {
            $this->current_page_parameters = $parameters;
            $this->current_route = count($parameters);
        }

        $request_uri = $_SERVER['REQUEST_URI']??"";
        $url_path = explode('/', $request_uri);
        $base_path = explode(
            '/',
            // @phpstan-ignore-next-line
            $this->config->getConfig(
                "ContentManager",
                "base_path",
                ""
            )
        );
        // Removing base path
        for ($i = 0; $i < count($base_path); $i++) {
            if ($url_path[$i] == $base_path[$i]) {
                unset($url_path[$i]);
            }
        }
        // Decoding url (%20 -> ' ').
        foreach ($url_path as &$url) {
            $url = urldecode($url);
        }
        // array_filter() removes, if there are any, empty values caused by
        // multiple slashes: example.com/id///page//foo//
        // array_values() reindexes the array.
        $route = array_values(array_filter($url_path));

        foreach ($this->current_page_parameters as $key => $parameter) {
            $_GET[$parameter] = (isset($route[$key])) ? $route[$key] : null;
        }
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
        );
    }
}
