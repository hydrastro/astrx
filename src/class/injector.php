<?php

/**
 * Class Injector
 */
class Injector
{
    /**
     * @var Config $config Config class.
     */
    private Config $config;
    /**
     * @var ErrorHandler $ErrorHandler Error Handler class.
     */
    private ErrorHandler $ErrorHandler;
    /**
     * @var MessageHandler $MessageHandler Message Handler class.
     */
    private MessageHandler $MessageHandler;
    /**
     * @var array<string, object> $classes Injector container classes.
     */
    private array $classes = array();
    /**
     * @var array<string, array<string,mixed>> $classesArgs Classes arguments.
     */
    private array $classesArgs;
    /**
     * @var array<int, array<mixed>> $messages Messages array.
     */
    public array $messages = array();
    /**
     * @var array<int, Throwable> $exceptions Exceptions objects array.
     */
    public array $exceptions = array();

    /**
     * Set Error Handler.
     * Sets the error handler.
     *
     * @param ErrorHandler $ErrorHandler
     */
    public function setErrorHandler(ErrorHandler $ErrorHandler)
    : void {
        $this->ErrorHandler = $ErrorHandler;
    }

    /**
     * Set Message Handler.
     * Sets the message handler.
     *
     * @param MessageHandler $MessageHandler
     */
    public function setMessageHandler(MessageHandler $MessageHandler)
    : void {
        $this->MessageHandler = $MessageHandler;
    }

    /**
     * Set config.
     * Sets the config class.
     *
     * @param Config $config
     */
    public function setConfig(Config $config)
    : void {
        $this->config = $config;
    }

    /**
     * Set Class Arguments.
     * Sets the arguments for a specific class.
     *
     * @param string               $class_name Class name.
     * @param array<string, mixed> $args       Class functions arguments.
     *
     * @return bool
     */
    public function setClassArgs(string $class_name, array $args)
    : bool {
        if (class_exists($class_name)) {
            $name = $this->getIndexName($class_name);
            $this->classesArgs[$name] = $args;

            return true;
        }
        $e = new Exception(ERROR_CLASS_NOT_FOUND);
        $this->exceptions[] = $e;
        $this->messages[] = array(
            MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
            MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
            MESSAGE_TEXT => $e->getMessage()
        );

        return false;
    }

    /**
     * Set Class.
     * Stores an already initialized class instance in the class container
     * array.
     *
     * @param object $class Class.
     *
     * @return void
     */
    public function setClass(object $class)
    {
        $name = $this->getIndexName(get_class($class));
        $this->classes[$name] = $class;
    }

    /**
     * Set Class.
     * Stores an already initialized class instance in the class container
     * array.
     *
     * @param string $class_name Class name.
     * @param bool   $create     Create if class instance doesn't exist.
     *
     * @return mixed
     */
    public function getClass(string $class_name, bool $create = true)
    : mixed {
        if ($this->hasClass($class_name)) {
            $name = $this->getIndexName($class_name);

            return $this->classes[$name];
        }
        if ($create) {
            return $this->createClass($class_name);
        }

        return null;
    }

    /**
     * Get Index Name.
     * Returns a string that will be used as an index when referencing to this
     * class. In this case it's just the class name.
     *
     * @param string $class Class name.
     *
     * @return string
     */
    public function getIndexName(string $class)
    : string {
        return $class;
    }

    /**
     * Has Class.
     * Checks if the injector has a class.
     *
     * @param string $class_name Class name.
     *
     * @return bool
     */
    public function hasClass(string $class_name)
    : bool {
        if (empty($this->classes)) {
            return false;
        }
        $name = $this->getIndexName($class_name);

        return (array_key_exists($name, $this->classes));
    }

    /**
     * Get Class Argument.
     * Returns a class arguments if there are any set.
     *
     * @param string $class_name Class name.
     * @param string $arg_name   Argument name.
     *
     * @return mixed
     */
    public function getClassArg(string $class_name, string $arg_name)
    : mixed {
        $name = $this->getIndexName($class_name);
        if (!isset($this->classesArgs[$name])) {
            return null;
        }
        if (!isset($this->classesArgs[$name][$arg_name])) {
            return null;
        }

        return $this->classesArgs[$name][$arg_name];
    }

    /**
     * Create Class.
     * Creates a class.
     *
     * @param string $class_name                  Class name.
     * @param bool   $share                       Share class: store among
     *                                            container known instances.
     * @param bool   $initialize_config_functions Initialize configuration
     *                                            functions.
     *
     * @return mixed
     */
    public function createClass(
        string $class_name,
        bool $share = true,
        bool $initialize_config_functions = true
    )
    : mixed {
        if (!class_exists($class_name)) {
            $e = new Exception(ERROR_CLASS_NOT_FOUND);
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => $e->getMessage()
            );

            return null;
        }
        try {
            $reflectedClass = new ReflectionClass($class_name);
            $dependencies = array();
            if ($reflectedClass->hasMethod("__construct")) {
                $constructor = $reflectedClass->getMethod("__construct");
                foreach ($constructor->getParameters() as $parameter) {
                    $arg_name = $parameter->getName();
                    $arg = $this->getClassArg($class_name, $arg_name);
                    if ($arg) {
                        $dependencies[] = $arg;
                    } elseif (!$parameter->isOptional()) {
                        if ($parameter->getType() === null) {
                            $e = new Exception(
                                ERROR_CLASS_OR_PARAMETER_NOT_FOUND
                            );
                            $this->exceptions[] = $e;
                            $this->messages[] = array(
                                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                                MESSAGE_TEXT => $e->getMessage()
                            );

                            return null;
                        }
                        $dependency_class_name = $parameter->getType();
                        $index
                            = $this->getIndexName($dependency_class_name);
                        if (!$this->hasClass($dependency_class_name)) {
                            $this->createClass($dependency_class_name);
                        }
                        $dependencies[] = $this->classes[$index];
                    }
                }
            }
            if (isset($this->config)) {
                $this->loadLang($class_name);
                $this->config->loadConfig($class_name);
            }
            $name = $this->getIndexName($class_name);
            $class = new $class_name(...$dependencies);
            if ($share) {
                $this->classes[$name] = $class;
            }
            if (($initialize_config_functions) &&
                (method_exists($class, "getConfigurationMethods"))) {
                foreach ($class->getConfigurationMethods() as $method) {
                    if (method_exists($class, $method)) {
                        $args = array();
                        $reflectedMethod = new ReflectionMethod(
                            $class, $method
                        );
                        foreach (
                            $reflectedMethod->getParameters() as $parameter
                        ) {
                            if (isset($this->config)) {
                                $args[]
                                    = $this->config->getConfig(
                                    $parameter->getName(),
                                    $name
                                );
                            } else {
                                $e = new Exception(
                                    ERROR_NO_ARGUMENTS_FOR_METHOD
                                );
                                $this->exceptions[] = $e;
                                $this->messages[] = array(
                                    MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                                    MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                                    MESSAGE_TEXT => $e->getMessage()
                                );

                                return null;
                            }
                        }
                        $class->$method(...$args);
                    }
                }
            }
            if (isset($this->ErrorHandler)) {
                $this->ErrorHandler->addClass($class);
            }
            if (isset($this->MessageHandler)) {
                $this->MessageHandler->addClass($class);
            }

            return $class;
        } catch (ReflectionException $e) {
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => ERROR_CLASS_REFLECTION . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Call Class Method.
     * Calls a class method.
     *
     * @param string               $class_name Class name.
     * @param string               $method     Method name.
     * @param array<string, mixed> $arguments  Arguments.
     *
     * @return mixed
     */
    public function callClassMethod(
        string $class_name,
        string $method,
        array $arguments = array()
    )
    : mixed {
        if ($this->hasClass($class_name)) {
            if (method_exists($class_name, $method)) {
                $class = $this->getClass($class_name);

                return $class->$method(...$arguments);
            }
            $e = new Exception(ERROR_CLASS_METHOD_NOT_FOUND);
        } else {
            $e = new Exception(ERROR_CLASS_NOT_FOUND);
        }
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
     * @param string $class Class name.
     *
     * @return void
     */
    public function loadLang(string $class)
    {
        $lang = $this->config->getConfig("language");
        if (!is_string($lang)) {
            return;
        }
        $class_filename = toSnakeCase($class);
        $lang_file = LANG_DIR . "$class_filename.$lang.php";
        if (file_exists($lang_file)) {
            require_once($lang_file);
        }
    }
}
