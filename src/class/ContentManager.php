<?php

declare(strict_types = 1);
/**
 * Class ContentManager.
 */
class ContentManager
{
    public const ERROR_INVALID_I18N_URL_ID = 0;
    public const ERROR_INVALID_I18N_KEYWORD = 1;
    public const ERROR_INVALID_LANGUAGE = 2;
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
            assert(is_array($parameters_config));
            foreach ($parameters_config as $parameter_config) {
                assert(is_string($parameter_config));
                $parameter_name = $this->config->getConfig(
                    "ContentManager",
                    $parameter_config,
                    ""
                );
                assert(is_string($parameter_name));
                // Puts the parameters on the url stack to $_GET
                $this->setCurrentPageParameters(array($parameter_name));
            }
        }

        // Setting the current language.
        $request = $this->injector->createClass("Request");
        assert($request instanceof Request);
        $language_parameter_name = $this->config->getConfig(
            "ContentManager",
            "language_parameter_name"
        );
        assert(is_string($language_parameter_name));
        $lang = $request->get($language_parameter_name, "");
        assert(is_string($lang));
        // If even the fallback to default language fails a catastrophic
        // error is triggered.
        if (!($this->config->setLang($lang))) {
            $fallback_lang = $this->config->getConfig(
                "ContentManager",
                "default_language"
            );
            assert(is_string($fallback_lang));
            if (!$this->config->setLang($fallback_lang)) {
                $this->results[] = array(
                    self::ERROR_INVALID_LANGUAGE,
                    array("lang" => $fallback_lang)
                );

                $catastrophe_message = $this->config->getConfig(
                    "ContentManager",
                    "language_catastrophe_message"
                );
                assert(is_string($catastrophe_message));
                trigger_error($catastrophe_message);

                return;
            }

            if ($url_rewrite) {
                $this->shiftCurrentPageParameters(1);
            }
        }

        // Now that the language files and all the bootstrap components have
        // been loaded we can inject the results maps into the error handler,
        // so we have a way nicer error/results display.
        // For instance, we're handling the errors of these classes:
        // Injector, Config, Prelude, ErrorHandler
        $this->ErrorHandler->addMultipleResultsMaps($this->getInitResultsMap());

        // We can now start building our response.
        $TemplateEngine = $this->injector->createClass("TemplateEngine");
        assert($TemplateEngine instanceof TemplateEngine);
        $response = $this->injector->createClass("Response");
        assert($response instanceof Response);

        // Getting the parameter name of the page id.
        $page_id_parameter_name = $this->config->getConfig(
            "ContentManager",
            "page_id_parameter_name",
            ""
        );
        assert(is_string($page_id_parameter_name));
        // Setting the current page id.
        $current_page_parameter = $request->get(
            $page_id_parameter_name,
            // fallback to the main page
            $this->config->getConfig("ContentManager", "main_page_id", "")
        );
        assert(is_string($current_page_parameter));

        // Creating database connection.
        // $config->loadConfig("PDO"); It's a built in class so its config
        // will just be loaded along with the main configs.
        $dsn = $this->config->getConfig("PDO", "db_type", "");
        assert(is_string($dsn));
        $host = $this->config->getConfig("PDO", "db_host", "");
        assert(is_string($host));
        $dbname = $this->config->getConfig("PDO", "db_name", "");
        assert(is_string($dbname));
        $passwd = $this->config->getConfig("PDO", "db_password", "");
        assert(is_string($passwd));
        $username = $this->config->getConfig("PDO", "db_username", "");
        assert(is_string($username));
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
        assert($pdo instanceof PDO);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Retrieving the information of the current page.
        $PageHandler = $this->injector->createClass("PageHandler");
        assert($PageHandler instanceof PageHandler);

        // Retrieving all the internationalized page ids and resolving them
        // against the loaded language file.
        $resolved_i18n_ids = array();
        foreach ($PageHandler->getInternationalizedPageIds() as $i18n_pages) {
            $url_id = $i18n_pages["url_id"];
            assert(is_string($url_id));
            $page_id = $i18n_pages["id"];
            assert(is_int($page_id));
            if (defined($url_id)) {
                $resolved_i18n_ids[constant($url_id)] = $page_id;
            } else {
                $this->results[] = array(
                    self::ERROR_INVALID_I18N_URL_ID,
                    array("url_id" => $page_id)
                );
            }
        }

        // Loading the current page.
        $current_page = null;
        // Checking whether the current page is an internationalized page.
        if (isset($resolved_i18n_ids[$current_page_parameter])) {
            $current_page = $PageHandler->getPage(
                $resolved_i18n_ids[$current_page_parameter]
            );
        } else {
            // Retrieving the non internationalized page id.
            $current_page_id = $PageHandler->getPageIdFromUrlId(
                $current_page_parameter
            );
            // Loading the page.
            if ($current_page_id !== null) {
                $current_page = $PageHandler->getPage($current_page_id);
            }
        }

        // Page loading failed, we're falling back to error 404.
        if ($current_page === null || $current_page->hidden) {
            http_response_code(404);
            $current_page = $PageHandler->getPage(
                $resolved_i18n_ids[WORDING_ERROR] // hehe
            );
            if ($current_page === null) {
                // Things have gone horribly wrong, we fall back to a
                // hardcoded error page.
                $current_page = $PageHandler->getErrorPage();
            }
        }
        assert($current_page instanceof Page);

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
            assert(is_object($controller));
            assert(method_exists($controller, "init"));
            $controller->init();
        }

        // If the controllers didn't send the response themselves, here we
        // fall back to the default template.
        $template = $TemplateEngine->loadTemplate("template");
        assert(is_object($template));
        assert(method_exists($template, "render"));
        // Setting up the template arguments.
        $this->setCurrentTemplateArgs($current_page);

        // I like to know how much all of this took, so I set an additional
        // parameter for the page rendering.
        $this->template_args["time"] = round(
            (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
            4
        );

        // Errors handling:
        //$this->template_args["results"] = $this->ErrorHandler->getResults();

        // Setting and sending the rendered page.
        $response->setContent($template->render($this->template_args));
        $response->send();
    }

    private function setCurrentTemplateArgs(Page $current_page)
    : void {
        // Setting the page meta tags.
        $this->template_args["index"] = $current_page->index;
        $this->template_args["follow"] = $current_page->follow;
        $this->template_args["content"] = $current_page->file_name;

        // Setting the page title and description.
        if ($current_page->i18n) {
            $this->config->loadPageLang($current_page->url_id);
            if (defined($current_page->url_id . "_PAGE_TITLE")) {
                $this->template_args["title"] = constant(
                    $current_page->url_id . "_PAGE_TITLE"
                );
            }
            if (defined($current_page->url_id . "_PAGE_DESCRIPTION")) {
                $this->template_args["description"] = constant(
                    $current_page->url_id . "_PAGE_DESCRIPTION"
                );
            }
        } else {
            $this->template_args["title"] = $current_page->title;
            $this->template_args["description"] = $current_page->description;
        }

        // Loading the page keywords.
        $this->config->loadKeywordsLang();
        $keywords_filter = function (array $keywords, bool $i18n) {
            return array_filter(
                array_map(
                    function ($item) use ($i18n) {
                        return $this->keywordsFilter($item, $i18n);
                    },
                    $keywords
                )
            );
        };
        $i18n_keywords = $keywords_filter($current_page->keywords, true);
        $normal_keywords = $keywords_filter($current_page->keywords, false);
        $keywords = array();
        foreach ($i18n_keywords as $keyword) {
            if (defined($keyword)) {
                $keywords[] = constant($keyword);
            } else {
                $this->results[] = array(
                    self::ERROR_INVALID_I18N_KEYWORD,
                    array("keyword" => $keyword)
                );
            }
        }
        $keywords = array_merge($keywords, $normal_keywords);

        // Keywords are all capitalised by default because keywords (which
        // can also use URL IDs constants) are (SHOULD) all be stored in
        // lowercase.
        $this->template_args["keywords"] = implode(
            ", ",
            array_map(function ($val) {
                assert(is_string($val));

                return ucwords($val);
            }, $keywords)
        );
    }

    /**
     * Keywords Filter.
     * This is a helper function used for filtering out I18N keywords from
     * non-I18N ones.
     *
     * @param array<string, mixed> $keyword Keyword array.
     * @param bool                 $i18n    I18N Flag.
     *
     * @return string|null
     */
    public function keywordsFilter(array $keyword, bool $i18n)
    : string|null {
        if (!($i18n ^ $keyword["i18n"])) {
            assert(is_string($keyword["keyword"]));

            return $keyword["keyword"];
        }

        return null;
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
        $config_base_path = $this->config->getConfig(
            "ContentManager",
            "base_path",
            ""
        );
        assert(is_string($config_base_path));
        $base_path = explode('/', $config_base_path);
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
            "ContentManager" => array(
                self::ERROR_INVALID_I18N_URL_ID => array(
                    500,
                    ERROR_INVALID_I18N_URL_ID, // url_id
                    ErrorHandler::LOG_LEVEL_WARNING
                ),
                self::ERROR_INVALID_I18N_KEYWORD => array(
                    500,
                    ERROR_INVALID_I18N_KEYWORD, // keyword
                    ErrorHandler::LOG_LEVEL_WARNING
                ),
                self::ERROR_INVALID_LANGUAGE => array(
                    500,
                    ERROR_INVALID_LANGUAGE,
                    ErrorHandler::LOG_LEVEL_NOTICE
                )

            )
        );
    }

    /**
     * Shift Current Page Parameters.
     * Shifts the currents page parameters array, it's useful when a
     * parameter is weak, like the language parameter.
     *
     * @param int $shift_positions Numbers of positions to shift.
     *
     * @return void
     */
    private function shiftCurrentPageParameters(int $shift_positions)
    : void {
        while ($shift_positions > 0) {
            array_shift($this->current_page_parameters);
            $shift_positions--;
        }
        $this->setCurrentPageParameters($this->current_page_parameters, false);
    }
}
