<?php
class Injector {
	/**
	 * @var Config $config Config class.
	 */
	private $config;
	/**
	 * @var ErrorHandler $ErrorHandler Error Handler class.
	 */
	private $ErrorHandler;
	/**
	 * @var MessageHandler $MessageHandler Message Handler class.
	 */
	private $MessageHandler;
	/**
	 * @var array $classes Injector container classes.
	 */
	private $classes = array();
	/**
	 * @var array $classesArgs Classes arguments.
	 */
	private $classesArgs;
	/**
	 * @var array $messages Messages array.
	 */
	public $messages = array();
	/**
	 * @var array $exceptions Exceptions objects array.
	 */
	public $exceptions = array();

	/**
	 * Injector constructor.
	 */
	public function __construct() {
		$ErrorHandler = new ErrorHandler();
		$config = new Config();
		$MessageHandler = new MessageHandler();
		$ErrorHandler->addClass($config);
		$MessageHandler->addClass($config);
		$ErrorHandler->addClass($MessageHandler);
		$MessageHandler->addClass($ErrorHandler);
		require(LANG_DIR . "injector.en.php");
		$this->setClass($config);
		$this->setClass($ErrorHandler);
		$this->setClass($MessageHandler);
		$this->setClass($this);
		$this->config = $config;
		$this->ErrorHandler = $ErrorHandler;
		$this->MessageHandler = $MessageHandler;
	}

	/**
	 * Set Class Arguments.
	 * Sets the arguments for a specific class.
	 *
	 * @param       $class_name
	 * @param array $args
	 *
	 * @return bool
	 */
	public function setClassArgs($class_name, $args) {
		if(!is_array($args)) {
			$e = new Exception(ERROR_INVALID_ARRAY);
			$this->exceptions[] = $e;
			$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
			                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
			                          MESSAGE_TEXT => $e->getMessage());

			return false;
		}
		if(class_exists($class_name)) {
			$name = $this->getIndexName($class_name);
			$this->classesArgs[$name] = $args;

			return false;
		}
		$e = new Exception(ERROR_CLASS_NOT_FOUND);
		$this->exceptions[] = $e;
		$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
		                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
		                          MESSAGE_TEXT => $e->getMessage());

		return true;
	}

	/**
	 * Set Class.
	 * Stores an already initialized class instance in the class container
	 * array.
	 *
	 * @param $class
	 *
	 * @return bool
	 */
	public function setClass($class) {
		if(is_object($class)) {
			$name = $this->getIndexName($class);
			$this->classes[$name] = $class;

			return true;
		}
		$e = new Exception(ERROR_INVALID_OBJECT);
		$this->exceptions[] = $e;
		$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
		                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
		                          MESSAGE_TEXT => $e->getMessage());

		return false;
	}

	/**
	 * @param      $class_name
	 * @param bool $create
	 *
	 * @return mixed|null
	 */
	public function getClass($class_name, $create = true) {
		if($this->hasClass($class_name)) {
			$name = $this->getIndexName($class_name);

			return $this->classes[$name];
		}
		if($create) {
			return $this->createClass($class_name);
		}

		return null;
	}

	/**
	 * Get Index Name.
	 * Returns a string that will be used as an index when referencing to this
	 * class. In this case it's just the class name.
	 *
	 * @param string|object $class
	 *
	 * @return string|null
	 */
	public function getIndexName($class) {
		if(is_object($class)) {
			return get_class($class);
		}
		if(is_string($class)) {
			return $class;
		}
		$e = new Exception(ERROR_INVALID_OBJECT);
		$this->exceptions[] = $e;
		$this->messages = array(HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());

		return null;
	}

	/**
	 * Has Class.
	 * Checks if the injector has a class.
	 *
	 * @param $class_name
	 *
	 * @return bool
	 */
	public function hasClass($class_name) {
		if(empty($this->classes)) {
			return false;
		}
		$name = $this->getIndexName($class_name);

		return (array_key_exists($name, $this->classes));
	}

	/**
	 * Get Class Argument.
	 * Returns a class arguments if there are any set.
	 *
	 * @param $class_name
	 * @param $arg_name
	 *
	 * @return mixed|null
	 */
	public function getClassArg($class_name, $arg_name) {
		$name = $this->getIndexName($class_name);
		if(isset($this->classesArgs[$name][$arg_name])) {
			return $this->classesArgs[$name][$arg_name];
		}

		return null;
	}

	/**
	 * Create Class.
	 * Creates a class.
	 *
	 * @param      $class_name
	 * @param bool $share
	 * @param bool $initialize_config_functions
	 *
	 * @return mixed|null
	 */
	public function createClass($class_name,
		$share = true,
		$initialize_config_functions = true) {
		if(!class_exists($class_name)) {
			$e = new Exception(ERROR_CLASS_NOT_FOUND);
			$this->exceptions[] = $e;
			$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
			                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
			                          MESSAGE_TEXT => $e->getMessage());

			return null;
		}
		try {
			$reflectedClass = new ReflectionClass($class_name);
			$dependencies = array();
			if($reflectedClass->hasMethod("__construct")) {
				$constructor = $reflectedClass->getMethod("__construct");
				foreach($constructor->getParameters() as $parameter) {
					$parameter_class = $parameter->getClass();
					if($parameter_class) {
						$arg_name = $parameter_class->getName();
					} else {
						$arg_name = $parameter->getName();
					}
					$arg = $this->getClassArg($class_name, $arg_name);
					if($arg) {
						$dependencies[] = $arg;
					} else {
						if(!$parameter->isOptional()) {
							if($parameter->getClass() === null) {
								$e = new Exception
								(ERROR_CLASS_OR_PARAMETER_NOT_FOUND);
								$this->exceptions[] = $e;
								$this->messages[]
									= array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
									        MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
									        MESSAGE_TEXT => $e->getMessage());

								return null;
							}
							$dependency_class_name = $parameter->getClass()
								->getName();
							$index
								= $this->getIndexName($dependency_class_name);
							if(!$this->hasClass($dependency_class_name)) {
								$this->createClass($dependency_class_name);
							}
							$dependencies[] = $this->classes[$index];
						}
					}
				}
			}
			if(isset($this->config)) {
				$this->loadLang($class_name);
				$this->config->loadConfig($class_name);
			}
			$name = $this->getIndexName($class_name);
			$class = new $class_name(...$dependencies);
			if($share) {
				$this->classes[$name] = $class;
			}
			if($initialize_config_functions) {
				if(method_exists($class, "getConfigurationMethods")) {
					foreach($class->getConfigurationMethods() as $method) {
						if(method_exists($class, $method)) {
							$args = array();
							$reflectedMethod = new ReflectionMethod($class,
								$method);
							foreach($reflectedMethod->getParameters() as
							        $parameter) {
								if(isset($this->config)) {
									$args[]
										= $this->config->getConfig($parameter->getName(),
										$name);
								} else {
									// ERROR: no arguments found for
									// configuration method.
									$this->exceptions = new Exception("ASDASD");
								}
							}
							$class->$method(...$args);
						}
					}
				}
			}
			if(isset($this->ErrorHandler)) {
				$this->ErrorHandler->addClass($class);
			}
			if(isset($this->MessageHandler)) {
				$this->MessageHandler->addClass($class);
			}

			return $class;
		} catch(ReflectionException $e) {
			$this->exceptions[] = $e;
			$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
			                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
			                          MESSAGE_TEXT => ERROR_CLASS_REFLECTION .
			                                    $e->getMessage());

			return null;
		}
	}

	/**
	 * Call Class Method.
	 * Calls a class method.
	 *
	 * @param      $class_name
	 * @param      $method
	 * @param null $arguments
	 *
	 * @return mixed|null
	 */
	public function callClassMethod($class_name, $method, $arguments = null) {
		if($arguments === null) {
			$arguments = array();
		}
		if(!is_array($arguments)) {
			$e = new Exception(ERROR_INVALID_FUNCTION_ARGUMENTS);
			$this->exceptions[] = $e;
			$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
			                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
			                          MESSAGE_TEXT => $e->getMessage());

			return null;
		}
		if($this->hasClass($class_name)) {
			if(method_exists($class_name, $method)) {
				$class = $this->getClass($class_name);

				return $class->$method(...$arguments);
			}
			$e = new Exception(ERROR_CLASS_METHOD_NOT_FOUND);
		} else {
			$e = new Exception(ERROR_CLASS_NOT_FOUND);
		}
		$this->exceptions[] = $e;
		$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
		                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
		                          MESSAGE_TEXT => $e->getMessage());

		return null;
	}

	/**
	 * Load Language.
	 * Loads a module language if there is any set.
	 *
	 * @param $class
	 */
	function loadLang($class) {
		$lang = $this->config->getConfig("language");
		$class = toSnakeCase($class);
		$lang_file = LANG_DIR . "$class.$lang.php";
		if(file_exists($lang_file)) {
			require_once(LANG_DIR . "$class.$lang.php");
		}
	}
}
