<?php

/**
 * Class Config.
 */
class Config
{
    /**
     * @var array<string, array<string, mixed>> $configuration Config array.
     */
    private array $configuration;
    /**
     * @var array<int, array<int, mixed>> $messages Errors array.
     */
    public array $messages = array();
    /**
     * @var array<int, Throwable> $exceptions Exceptions objects array.
     */
    public array $exceptions = array();

    /**
     * Config Constructor.
     */
    public function __construct()
    {
        $this->configuration = require(CONFIG_DIR . "config.php");
        $lang = $this->getConfig("language");
        if (!is_string($lang)) {
            $e = new Exception(
                "An error occurred while loading the config file."
            );
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => $e->getMessage()
            );

            return;
        }
        require(LANG_DIR . "$lang.php");
    }

    /**
     * Add Config Array.
     * Merges a given configuration array with the existing configuration array.
     *
     * @param array<string, array<string, mixed>> $array Configuration array.
     *
     * @return void
     */
    public function addConfigArray(array $array)
    {
        $this->configuration = array_merge($this->configuration, $array);
    }

    /**
     * Add Specific Config.
     * Add a value to a specific index in the config array.
     *
     * @param string               $index Array index (class name).
     * @param array<string, mixed> $value Configuration array.
     *
     * @return void
     */
    public function addSpecificConfig(string $index, mixed $value)
    {
        $this->configuration[$index] = $value;
    }

    /**
     * Load Config.
     * Loads a module configuration if there is any set.
     *
     * @param string $class Class name.
     *
     * @return bool
     */
    public function loadConfig(string $class)
    : bool {
        $class = toSnakeCase($class);
        $class_path = CONFIG_DIR . "$class.config.php";

        return $this->loadConfigFile($class_path);
    }

    /**
     * Load Config File.
     * Loads the configuration file of a class.
     *
     * @param string $config_file                Configuration file name.
     * @param bool   $handle_not_found_exception Error trigger on failure.
     *
     * @return bool
     */
    private function loadConfigFile(
        string $config_file,
        bool $handle_not_found_exception = false
    )
    : bool {
        if (!file_exists($config_file)) {
            if ($handle_not_found_exception) {
                $e = new Exception(ERROR_NONEXISTENT_FILE);
                $this->exceptions[] = $e;
                $this->messages[] = array(
                    MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                    MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                    MESSAGE_TEXT => $e->getMessage()
                );
            }

            return false;
        }
        $this->configuration = array_merge(
            $this->configuration,
            require($config_file)
        );

        return true;
    }

    /**
     * Get Config.
     * Returns a config which may be specific for a class.
     * It directly maps to $configuration[$class_name][$config_name]
     * or $configuration[$config_name] if no class is provided.
     *
     * @param string $config_name Configuration name.
     * @param string $class_name  Configuration class name.
     *
     * @return mixed
     */
    public function getConfig(string $config_name, string $class_name = "")
    : mixed {
        if (is_string($config_name)) {
            if ($class_name !== "" &&
                array_key_exists($class_name, $this->configuration) &&
                array_key_exists(
                    $config_name,
                    $this->configuration[$class_name]
                )) {
                return $this->configuration[$class_name][$config_name];
            }
            if (array_key_exists($config_name, $this->configuration)) {
                return $this->configuration[$config_name];
            }
        }
        $error_message = (defined("ERROR_INVALID_ARRAY_INDEX")) ?
            ERROR_INVALID_ARRAY_INDEX : "Invalid array index.";
        $e = new Exception($error_message);
        $this->exceptions[] = $e;
        $this->messages[] = array(
            MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
            MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
            MESSAGE_TEXT => $e->getMessage()
        );

        return null;
    }

    /**
     * Load Language.
     * Loads a module language if there is any set.
     *
     * @param string $class_name Class name.
     *
     * @return void
     */
    public function loadLang(string $class_name)
    {
        $lang = $this->getConfig("language");
        if (!is_string($lang)) {
            return;
        }
        $class_filename = toSnakeCase($class_name);
        $lang_file = LANG_DIR . "$class_filename.$lang.php";
        if (file_exists($lang_file)) {
            require_once($lang_file);
        }
    }

    /**
     * Configuration Methods Helper.
     * Function that retrieves a class configuration methods and calls them,
     * injecting the proper configurations.
     *
     * @param string $class_name
     * @param object $class_instance
     *
     * @return bool
     */
    public function configurationMethodsHelper(
        string $class_name,
        object $class_instance
    )
    : bool {
        if (!method_exists($class_instance, "getConfigurationMethods")) {
            return false;
        }
        try {
            foreach ($class_instance->getConfigurationMethods() as $method) {
                if (!method_exists($class_instance, $method)) {
                    continue;
                }
                $args = array();
                $reflectedMethod = new ReflectionMethod(
                    $class_instance, $method
                );
                foreach (
                    $reflectedMethod->getParameters() as $parameter
                ) {
                    $args[]
                        = $this->getConfig(
                        $parameter->getName(),
                        $class_name
                    );
                }
                $class_instance->$method(...$args);
            }

            return true;
        } catch (ReflectionException) {
            return false;
        }
    }
}
