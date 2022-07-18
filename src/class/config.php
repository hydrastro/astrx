<?php
/** @noinspection PhpUnused */

declare(strict_types = 1);
/**
 * Class Config.
 */
class Config
{
    public const ERROR_CONFIG_FILE_NOT_FOUND = 0;
    public const ERROR_CONFIG_NOT_FOUND = 1;
    public const ERROR_INVALID_LANGUAGE = 2;
    /**
     * @var array<int, array<int, mixed>> $results Results array.
     */
    public array $results = array();
    /**
     * @var string $lang Language.
     */
    public string $lang;
    /**
     * @var array<string, array<string, mixed>> $configuration Config array.
     */
    private array $configuration;
    /**
     * @var array<int, string> $deferred_lang_classes Classes whose language
     * file is yet to be loaded.
     */
    private array $deferred_lang_classes = array();

    /**
     * Config Constructor.
     */
    public function __construct()
    {
        $this->configuration = require(CONFIG_DIR . "config.php");
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
    : void {
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
    : void {
        $this->configuration[$index] = $value;
    }

    /**
     * Configuration Methods Helper.
     * Function that retrieves a class configuration methods and calls them,
     * injecting the proper configurations.
     *
     * @param object $class_instance Class instance.
     * @param string $class_name     Class name.
     *
     * @return bool
     */
    public function configurationMethodsHelper(
        object $class_instance,
        string $class_name
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
                        $class_name,
                        $parameter->getName()
                    );
                }
                $class_instance->$method(...$args);
            }

            return true;
        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * Get Config.
     * Returns a config which may be specific for a class.
     * It directly maps to $configuration[$class_name][$config_name]
     * or $configuration[$config_name] if no class is provided.
     *
     * @param string $class_name  Configuration class name.
     * @param string $config_name Configuration name.
     * @param mixed  $fallback    Fallback configuration.
     *
     * @return mixed
     */
    public function getConfig(
        string $class_name,
        string $config_name,
        mixed $fallback = null
    )
    : mixed {
        if (array_key_exists($class_name, $this->configuration) &&
            array_key_exists(
                $config_name,
                $this->configuration[$class_name]
            )) {
            return $this->configuration[$class_name][$config_name];
        }
        if ($fallback !== null) {
            return $fallback;
        }
        $this->results[] = array(
            self::ERROR_CONFIG_NOT_FOUND,
            array("class_name" => $class_name, "config_name" => $config_name)
        );

        return null;
    }

    /**
     * Load Class Language and Configurations.
     * This is a helper function which loads a given class language and
     * config files.
     *
     * @param object $_class_instance Class instance.
     * @param string $class_name      Class name.
     *
     * @return void
     * @noinspection PhpUnusedParameterInspection
     */
    public function loadClassLangAndConfig(
        object $_class_instance,
        string $class_name
    )
    : void {
        if (isset($this->lang)) {
            $this->loadLang($class_name);
        } else {
            $this->deferred_lang_classes[] = $class_name;
        }
        $this->loadConfig($class_name);
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
    : void {
        $lang = $this->lang;
        $class_filename = toSnakeCase($class_name);
        $lang_file = LANG_DIR . "$class_filename.$lang.php";
        if (file_exists($lang_file)) {
            require_once($lang_file);
        }
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

        $handle_not_found_exception = false;
        if (!file_exists($class_path)) {
            return false;
        }
        $this->configuration = array_merge(
            $this->configuration,
            require($class_path)
        );

        return true;
    }

    /**
     * Add Deferred Lang Class.
     * Adds a class to a list, so it's language file will be loaded later,
     * after a language has finally been set.
     *
     * @param object $class
     *
     * @return void
     */
    public function addDeferredLangClass(object $class)
    : void {
        $this->deferred_lang_classes[] = get_class($class);
    }

    /**
     * Set Lang and Load Deferred.
     * Sets the language and loads the deferred classes' language files.
     *
     * @param string $lang
     *
     * @return bool
     */
    public function setLangAndLoadDeferred(string $lang)
    : bool {
        $this->lang = $lang;
        $languages = $this->getConfig("Prelude", "available_languages");
        if (!is_array($languages) || !in_array($lang, $languages)) {
            return false;
        }
        foreach ($this->deferred_lang_classes as $class) {
            $this->loadLang($class);
        }

        return true;
    }
}
