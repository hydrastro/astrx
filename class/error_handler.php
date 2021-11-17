<?php
class ErrorHandler {
	/**
	 * @var array classes Classes.
	 */
	private $classes = array();
	/**
	 * @var array $errors Errors string array.
	 */
	protected $errors = array();
	/**
	 * @var array $exceptions Exceptions (objects).
	 */
	protected $exceptions = array();

	/**
	 * ErrorHandler constructor.
	 */
	public function __construct() {
		set_error_handler(array($this, 'errorHandler'));
		set_exception_handler(array($this, 'exceptionsHandler'));
		register_shutdown_function(array($this, 'shutdownHandler'));
		$this->classes[] = $this;
	}

	/**
	 * Exceptions Handler.
	 * Stores exceptions in the class instance for future display (when
	 * shutdownHandler is called).
	 *
	 * @param $e
	 */
	public function exceptionsHandler($e) {
		$this->exceptions[] = $e;
	}

	/**
	 * Error Handler.
	 * Throws errors as exceptions.
	 *
	 * @param $errno
	 * @param $errstr
	 * @param $errfile
	 * @param $errline
	 *
	 * @throws \ErrorException
	 */
	public function errorHandler($errno, $errstr, $errfile, $errline) {
		$level = error_reporting();
		if(($level & $errno) === 0) {
			return;
		}
		throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
	}

	/**
	 * Shutdown handler.
	 * Called when a script dies, handles fatal errors.
	 * It's registered with register_shutdown_function.
	 */
	public function shutdownHandler() {
		$errors = $this->getErrors();
		$exceptions = $this->getExceptions();
		if(DEBUG_MODE && !empty($errors) || !empty($exceptions)) {
			// Here we may want something nicer
			echo "<h1>Error</h1><pre>";
			print_r($errors);
			print_r($exceptions);
		}
	}

	/**
	 * Add Class.
	 * Adds a class to the class array.
	 *
	 * @param $class
	 */
	public function addClass($class) {
		if(is_object($class)) {
			$this->classes[] = $class;

			return;
		}
		$e = new Exception(ERROR_INVALID_OBJECT);
		$this->exceptions[] = $e;
		$this->errors[] = $e->getMessage();
	}

	/**
	 * Remove Class.
	 * Removes a class from the class array.
	 *
	 * @param $class
	 */
	public function removeClass($class) {
		if(is_object($class)) {
			if(($key = array_search($class, $this->classes, true)) !== false) {
				unset($this->classes[$key]);

				return;
			}
		}
		$e = new Exception(ERROR_INVALID_ARRAY_INDEX);
		$this->exceptions[] = $e;
		$this->errors[] = $e->getMessage();
	}

	/**
	 * Get Exceptions.
	 * Retrieves and returns the exceptions of all the classes handled.
	 * @return array
	 */
	public function getExceptions() {
		$exceptions = array();
		if(empty($this->classes)) {
			return array();
		}
		foreach($this->classes as $class) {
			if(property_exists($class, 'exceptions') &&
			   is_array($class->exceptions)) {
				foreach($class->exceptions as $exception) {
					$exceptions[] = $exception;
				}
			}
		}

		return $exceptions;
	}

	/**
	 * Get Errors.
	 * Retrieves and returns the errors of all the classes handled.
	 * @return array
	 */
	public function getErrors() {
		$errors = array();
		if(empty($this->classes)) {
			return array();
		}
		foreach($this->classes as $class) {
			if(property_exists($class, 'errors') && is_array($class->errors)) {
				foreach($class->errors as $error) {
					$errors[] = $error;
				}
			}
		}

		return $errors;
	}
}
