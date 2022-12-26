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
            "UrlHandler",
            "url_rewrite",
            false
        );

        // Instantiating the Url Handler.
        // This class is necessary for handling the current url parameters,
        // here specifically for getting the current language and the page id.
        $UrlHandler = $this->injector->getClass("UrlHandler");
        assert($UrlHandler instanceof UrlHandler);

        // Setting the current language.
        $request = $this->injector->createClass("Request");
        assert($request instanceof Request);
        $language_parameter_name = $UrlHandler->getParameterName(
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
            $language_catastrophe_message = $this->config->getConfig(
                "ContentManager",
                "language_catastrophe_message",
                "Error: no language file could be loaded."
            );
            assert(is_string($language_catastrophe_message));
            assert(
                $this->config->setLang($fallback_lang),
                $language_catastrophe_message
            );
            // Fixing the current page parameters.
            if ($url_rewrite) {
                $UrlHandler->setParameter(
                    "language_parameter_name",
                    $fallback_lang
                );
                $UrlHandler->shiftCurrentPageParameters(1);
            }
        }

        // Now that the language files and all the bootstrap components have
        // been loaded we can inject the results maps into the error handler,
        // so we have a way nicer error/results display.
        // For instance, we're handling the errors of these classes:
        // Injector, Config, Prelude, ErrorHandler
        foreach ($this->getInitResultsMap() as $class_name => $class_map) {
            $this->ErrorHandler->addResultsMap($class_name, $class_map);
        }

        // We can now start building our response.
        $TemplateEngine = $this->injector->createClass("TemplateEngine");
        assert($TemplateEngine instanceof TemplateEngine);
        $response = $this->injector->createClass("Response");
        assert($response instanceof Response);

        // Getting the parameter name of the page id.
        $page_id_parameter_name = $UrlHandler->getParameterName(
            "page_id_parameter_name"
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
        // We could create it through the Injector, it doesn't matter. This
        // class is required either way, so it would be just a useless
        // complication.
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
        $pdo = new PDO(
            $dsn . ":host=" . $host . ";dbname=" . $dbname . ";",
            $username,
            $passwd
        );
        $this->injector->setClass($pdo);
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
            assert(defined($url_id));
            $resolved_i18n_ids[constant($url_id)] = $page_id;
        }

        // Loading the current page.
        $current_page = null;
        // Checking whether the current page is an internationalized page.
        if (array_key_exists($current_page_parameter, $resolved_i18n_ids)) {
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
        $this->injector->setClass($current_page);
        $UrlHandler->setParameter(
            "page_id_parameter_name",
            $current_page->resolved_url_id
        );

        // Setting the default template name.
        $template_file_name = $this->config->getConfig(
            "ContentManager",
            "default_template",
            "default"
        );
        assert(is_string($template_file_name));

        // Loading language files.
        $this->config->loadPageLang($current_page->url_id);
        $this->config->loadKeywordsLang();

        if ($current_page->controller) {
            $controller_name = $this->getControllerName(
                $current_page->file_name
            );
            spl_autoload_register(function (string $class)
            : void {
                if (strpos($class, "Controller")) {
                    require CONTROLLER_DIR . $class . ".php";
                }
            }, true, true);
            $controller = $this->injector->getClass($controller_name);
            // The controller constructor has set up this page's current url
            // in the url handler class, so now we can handle sessions.
        }
        $this->handleSession($UrlHandler, $request, $response);

        // Setting up the template arguments if the page has a template.
        // The template handler is called before the controller because the
        // controller may overwrite the render arguments.
        if ($current_page->template) {
            // Falling back to the default template if a custom template isn't set.
            if ($current_page->template_file_name !== "") {
                $template_file_name = $current_page->template_file_name;
            }
            spl_autoload_register(function (string $class)
            : void {
                if (strpos($class, "TemplateHandler")) {
                    require TEMPLATE_HANDLER_DIR . $class . ".php";
                }
            }, true, true);
            $TemplateHandler = $this->injector->getClass(
                $this->getTemplateHandlerName($template_file_name)
            );
            assert(is_object($TemplateHandler));
            assert(method_exists($TemplateHandler, "getTemplateArgs"));
            $this->template_args = $TemplateHandler->getTemplateArgs();
        }

        // Calls to controllers.
        // Controllers can either build a response themselves and send it
        // or alternatively they can fall back here to default template.
        // Controllers can edit the default template arguments;
        // ContentManager can be injected and template_args is public.
        // So $this->ContentManager->template_args["key"] = "value";
        if ($current_page->controller) {
            assert(isset($controller));
            assert(is_object($controller));
            assert(method_exists($controller, "init"));
            $controller->init();
        }

        // Loading the template.
        $template = $TemplateEngine->loadTemplate($template_file_name);

        // Getting the very last template arguments for rendering.
        if ($current_page->template) {
            if (!isset($TemplateHandler)) {
                $TemplateHandler = $this->injector->getClass(
                    $this->getTemplateHandlerName($template_file_name)
                );
                assert(is_object($TemplateHandler));
            }
            if (method_exists($TemplateHandler, "anyLastArgs")) {
                $this->template_args = array_merge(
                    $this->template_args,
                    $TemplateHandler->anyLastArgs()
                );
            }
        }

        // Setting and sending the rendered page.
        assert(is_object($template));
        assert(method_exists($template, "render"));
        $response->setContent($template->render($this->template_args));
        $response->send();
    }

    /**
     * Handle Session.
     * Initializes the session.
     *
     * @param UrlHandler $UrlHandler URL Handler class.
     * @param Request    $request    Request class.
     * @param Response   $response   Response class.
     *
     * @return void
     */
    private function handleSession(
        UrlHandler $UrlHandler,
        Request $request,
        Response $response
    )
    : void {
        // Setting the session handler.
        $SessionHandler = $this->injector->getClass("SecureSessionHandler");
        assert($SessionHandler instanceof SecureSessionHandler);
        session_set_save_handler($SessionHandler, true);

        // Adjusting the Session ID in the URL Handler.
        // We now have a complete URL: if there are extra parameters we check
        // if the last parameter may be a session ID, and in case if it is,
        // we initialize a new session with that ID, otherwise we log an
        // attempt of session id fixation.
        $session_id_parameter_name = $UrlHandler->getParameterName(
            "session_id_parameter_name"
        );
        assert(is_string($session_id_parameter_name));
        $UrlHandler->setSessionId($session_id_parameter_name);

        $session_id_parameter = $request->get(
            $session_id_parameter_name,
            ""
        );
        assert(is_string($session_id_parameter));

        if ($session_id_parameter !== "") {
            if ($SessionHandler->validateId($session_id_parameter)) {
                session_id($session_id_parameter);
            } else {
            }
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $current_session_id = session_id();
        assert(is_string($current_session_id));
        $UrlHandler->setParameter(
            "session_id_parameter_name",
            $current_session_id
        );

        // Handling the user inputs.
        $prg_token_parameter_name = $UrlHandler->getParameterName(
            "prg_token_parameter_name"
        );
        assert(is_string($prg_token_parameter_name));
        $PostRedirectGet = $this->injector->getClass("PostRedirectGet");
        assert($PostRedirectGet instanceof PostRedirectGet);
        // Handling a POST request: storing the data into the session and
        // redirecting the user.
        if ($_POST !== array()) {
            // Storing the POST data.
            $data = serialize($_POST);
            $token = hash_hmac("SHA256", $data, $current_session_id);
            $PostRedirectGet->store($token, $_POST);

            // Adjusting the redirect URL.
            $UrlHandler->setCurrentPageEndingParameters(
                array(
                    "prg_token_parameter_name" => $token,
                    "session_id_parameter_name" => $current_session_id
                )
            );

            $redirect_url = $UrlHandler->getUrl();

            $response->setStatusCode(Response::HTTP_FOUND);
            $response->addHeader("Location: " . $redirect_url);
            $response->send();
            echo "FUUUU";
            die();
        }
        // Checking GET requests of the PostRedirectGet pattern.
        $UrlHandler->setPRGToken($prg_token_parameter_name);
        $prg_token = $request->get($prg_token_parameter_name);
        if ($prg_token !== null) {
            assert(is_string($prg_token));
            $prg_data = $PostRedirectGet->load($prg_token);
            assert(is_array($prg_data));
            $_POST = array_merge($_POST, $prg_data);
        }
    }

    /**
     * Get Controller Name.
     * Returns the class name of a page controller.
     *
     * @param string $file_name
     *
     * @return string
     */
    private function getControllerName(string $file_name)
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
     * Get Template Handler Name.
     * Returns the class name of a template handler.
     *
     * @param string $file_name
     *
     * @return string
     */
    private function getTemplateHandlerName(string $file_name)
    : string {
        return str_replace(
                   '_',
                   '',
                   ucwords(
                       $file_name,
                       '_'
                   )
               ) . "TemplateHandler";
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
                Injector::ERROR_CLASS_NOT_FOUND_2 => array(
                    500,
                    ERROR_CLASS_NOT_FOUND, // class_name
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                Injector::ERROR_CLASS_REFLECTION => array(
                    500,
                    ERROR_CLASS_REFLECTION, // message
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
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
            "UrlHandler" => array(
                UrlHandler::ERROR_UNDEFINED_PARAMETER_NAME => array(
                    500,
                    ERROR_UNDEFINED_PARAMETER_NAME, // parameter_name
                    ErrorHandler::LOG_LEVEL_ERROR
                )
            )
        );
    }
}
