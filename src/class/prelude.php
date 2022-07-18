<?php

declare(strict_types = 1);
/**
 * Class Prelude.
 */
class Prelude
{
    public const ERROR_PDO_EXCEPTION = 0;
    /**
     * @var array<int, array<int, mixed>> $results Results array.
     */
    public array $results = array();

    /**
     * Prelude Constructor.
     */
    public function __construct()
    {
        // Loading core classes.
        $ErrorHandler = new ErrorHandler();
        $config = new Config();

        // Now we can relax. We have a custom error handler.

        // Wiring together the Error Handler.
        $ErrorHandler->addClass($this);
        $ErrorHandler->addClass($config);

        // Setting up environment.
        $environment = $config->getConfig(
            "Prelude",
            "environment",
            $ErrorHandler::ENVIRONMENT_DEVELOPMENT
        );
        // @phpstan-ignore-next-line
        $ErrorHandler->setEnvironment($environment);

        $config->loadLang("injector");
        $injector = new Injector();

        // Configuring the injector to load config and auto-wire stuff.
        $injector->addHelper($ErrorHandler, "addClass");
        $injector->addHelper($config, "loadClassLangAndConfig");
        $injector->addHelper($config, "configurationMethodsHelper");

        // TODO: INTERPOLATION
        // TODO: TEST.
        // TODO: clean everything and commit.
        // TODO: change injector ->getType()->getName(); / split and check
        // TODO:  CHECK THAT getType returns ReflectionNamedType
        // TODO: errorHandler->peacefullyDie();

        // Adding existing classes to the injector container.
        $injector->setClass($config);
        $injector->setClass($ErrorHandler);
        $injector->setClass($this);
        $ErrorHandler->addClass($injector);

        // Creating database connection.
        // $config->loadConfig("PDO"); It's a built in class so its config
        // will just be loaded along with the main configs.
        $dsn = $config->getConfig("PDO", "db_type", "");
        $host = $config->getConfig("PDO", "db_host", "");
        $dbname = $config->getConfig("PDO", "db_name", "");
        $passwd = $config->getConfig("PDO", "db_password", "");
        $username = $config->getConfig("PDO", "db_username", "");
        $injector->setClassArgs(
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
        try {
            $pdo = $injector->createClass("PDO");
            if ($pdo === null) {
                // call error handler to peacefully die
                return;
            }
        } catch (PDOException $e) {
            $this->results[] = array(
                self::ERROR_PDO_EXCEPTION,
                array("message" => $e->getMessage())
            );

            return;
        }
        /**
         * @var PDO $pdo PDO.
         */
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        // Finally creating the Content Manager class.
        $cms = $injector->getClass("ContentManager");
        if ($cms === null) {
            // call error handler to peacefully die
            return;
        }
        /**
         * @var ContentManager $cms Content Manager.
         */
        $cms->init();
    }

    private function getFatalErrorsMap()
    : array
    {
        // NOTE:
        // THIS SHOULD BE INJECTED INTO ERROR HANDLER
        // IF THINGS GO VERY BAD WE WONT BE ABLE TO INJECT THE ARRAY FOR THE
        // INJECTOR AND THE TEMPLATE ENGINE/HIGHER LEVEL CLASSES
        // SO: SPLIT THIS IN TWO: ONE FOR THE CRITICAL CLASSES ERROR HANDLER,
        // PRELUDE AND CONFIG. AND THE OTHER ONE FOR THE REST
        // WE GRADUALLY INJECT SHIT INTO THE ERROR HANDLER AS WE CRATE CLASSES

        // NOTE: this map should be injected to the error handler AFTER we
        // have also loaded the language files and the settings of that class

        // but lets build the array first
        return array(
            -"Injector" => array(
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
            "TemplateEngine" => array(
                TemplateEngine::ERROR_INVALID_PARSE_MODE => array(
                    500,
                    ERROR_INVALID_PARSE_MODE,
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_TEMPLATE_CLASS_CREATION => array(
                    500,
                    ERROR_TEMPLATE_CLASS_CREATION,
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_TEMPLATE_AST_INCONSISTENCY => array(
                    500,
                    ERROR_TEMPLATE_AST_INCONSISTENCY,
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_UNDEFINED_TOKEN_ARGUMENT => array(
                    500,
                    ERROR_UNDEFINED_TOKEN_ARGUMENT, // parent, args
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_UNDEFINED_TOKEN_ARGUMENT_2 => array(
                    500,
                    ERROR_UNDEFINED_TOKEN_ARGUMENT, // parent, args
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_INVALID_DEREFERENCE => array(
                    500,
                    ERROR_INVALID_DEREFERENCE, // value, args
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_TEMPLATE_FILE_NOT_FOUND => array(
                    500,
                    ERROR_TEMPLATE_FILE_NOT_FOUND, // template, template_file
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_MALFORMED_TAG_CHANGE => array(
                    500,
                    ERROR_MALFORMED_TAG_CHANGE,
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_UNCLOSED_TOKEN => array(
                    500,
                    ERROR_UNCLOSED_TOKEN,
                    ErrorHandler::LOG_LEVEL_ERROR
                ),

                TemplateEngine::ERROR_MALFORMED_TAG_CHANGE_2 => array(
                    500,
                    ERROR_MALFORMED_TAG_CHANGE,
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_UNCLOSED_TOKEN_2 => array(
                    500,
                    ERROR_UNCLOSED_TOKEN,
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_LOOP_TOKEN_MISMATCH => array(
                    500,
                    ERROR_LOOP_TOKEN_MISMATCH, // opening_tag, closing_tag
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_UNCLOSED_LOOP_TOKEN => array(
                    500,
                    ERROR_UNCLOSED_LOOP_TOKEN, // unclosed_tokens
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
                TemplateEngine::ERROR_TEMPLATE_EVALUATION => array(
                    500,
                    ERROR_TEMPLATE_EVALUATION, // message
                    ErrorHandler::LOG_LEVEL_ERROR
                )
            ),
            "Config" => array(
                Config::ERROR_CONFIG_FILE_NOT_FOUND => array(
                    500,
                    ERROR_CONFIG_FILE_NOT_FOUND, // config_file
                    ErrorHandler::LOG_LEVEL_ERROR
                ),
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
            )
        );
    }
}
