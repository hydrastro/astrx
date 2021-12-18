<?php

class Config
{
    /**
     * @var array<string, mixed> $configuration Config array.
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
        require(LANG_DIR . "$lang.php");
    }

    /**
     * Add Config Array.
     * Merges a given configuration array with the existing configuration array.
     *
     * @param array<string, mixed> $array
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
     * @param string $index
     * @param mixed  $value
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
     * @param string $class
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
     * @param string $config_file
     * @param bool   $handle_not_found_exception
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
     * @param string $config_name
     * @param string $class_name
     *
     * @return mixed
     */
    public function getConfig(string $config_name, string $class_name = "")
    : mixed {
        if (is_string($config_name)) {
            if ($class_name === "" &&
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
}
