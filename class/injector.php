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
	 * @var array $classes Injector container classes.
	 */
	private $classes = array();
	/**
	 * @var array $classesArgs Classes arguments.
	 */
	private $classesArgs;
	/**
	 * @var array $errors Errors array.
	 */
	public $errors = array();
	/**
	 * @var array $exceptions Exceptions objects array.
	 */
	public $exceptions = array();

	/**
	 * Injector constructor.
	 *
	 * @param Config       $config
	 * @param ErrorHandler $ErrorHandler
	 */
	public function __construct(ErrorHandler $ErrorHandler, Config $config) {
		$ErrorHandler->addClass($config);
		new Autoloader($config);
		$this->setClass($config);
		$this->setClass($ErrorHandler);
		$this->setClass($this);
	}

	/**
	 * Set Class Arguments.
	 * Sets the arguments for a specific class.
	 *
	 * @param       $class_name
	 * @param array $args
	 */
	public function setClassArgs($class_name, array $args) {
		if(class_exists($class_name)) {
			$name = $this->getIndexName($class_name);
			$this->classesArgs[$name] = $args;

			return;
		}
		$e = new Exception();
		$this->exceptions[] = $e;
		$this->errors[] = array(HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
	}

	/**
	 * Set Class.
	 * Stores an already initialized class instance in the class container
	 * array.
	 *
	 * @param $class
	 */
	public function setClass($class) {
		if(is_object($class)) {
			$name = $this->getIndexName($class);
			$this->classes[$name] = $class;

			return;
		}
		$e = new Exception(ERROR_INVALID_OBJECT);
		$this->exceptions[] = $e;
		$this->errors[] = array(HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
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
		$this->errors = array(HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());

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
			$this->errors[] = array(HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());

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
						// php 5.0.3
						if(!$parameter->isOptional()) {
							if($parameter->getClass() === null) {
								// or param. not found
								$e = new Exception
								(ERROR_CLASS_OR_PARAMETER_NOT_FOUND);
								$this->exceptions[] = $e;
								$this->errors[] = array(HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());

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
								$args[]
									= $this->config->getConfig($parameter->getName(),
									$name);
							}
							$class->$method(...$args);
						}
					}
				}
			}
			if(isset($this->ErrorHandler)) {
				$this->ErrorHandler->addClass($class);
			}

			return $class;
		} catch(ReflectionException $e) {
			$this->exceptions[] = $e;
			$this->errors[] = array(HTTP_INTERNAL_SERVER_ERROR, ERROR_CLASS_REFLECTION . $e->getMessage());

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
	 * @return void
	 */
	public function callClassMethod($class_name, $method, $arguments = null) {
		if($arguments === null) {
			$arguments = array();
		}
		if(!is_array($arguments)) {
			$e = new Exception(ERROR_INVALID_FUNCTION_ARGUMENTS);
			$this->exceptions[] = $e;
			$this->errors[] = array(HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());

			return;
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
		$this->errors[] = array(HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
	}
}
