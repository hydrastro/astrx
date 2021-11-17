<?php
class ErrorHandler {
	/**
	 * @var array classes Classes.
	 */
	private $classes = array();
	/**
	 * @var array $exceptions Exceptions (objects).
	 */
	private $exceptions = array();
	
	/**
	 * ErrorHandler constructor.
	 */
	public function __construct() {
		set_error_handler(array($this, 'errorHandler'));
		set_exception_handler(array($this, 'exceptionsHandler'));
		register_shutdown_function(array($this, 'shutdownHandler'));
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
		if(DEBUG_MODE &&
		   isset($this->exceptions) &&
		   !empty($this->exceptions)) {
			// Here we may want something nicer
			echo "<h1>Error</h1><pre>";
			print_r($this->getErrors());
			print_r($this->exceptions);
		}
	}
	/**
	 * Add Class.
	 * Adds a class to the class array.
	 *
	 * @param $class
	 *
	 * @throws \Exception
	 */
	public function addClass($class) {
		if(is_object($class)) {
			$this->classes[] = $class;
			return;
		}
		throw new Exception(ERROR_INVALID_OBJECT);
	}
	/**
	 * Remove Class.
	 * Removes a class from the class array.
	 *
	 * @param $class
	 *
	 * @throws \Exception
	 */
	public function removeClass($class) {
		if(is_object($class)) {
			if(($key = array_search($class, $this->classes, true)) !== false) {
				unset($this->classes[$key]);
				return;
			}
		}
		throw new Exception(ERROR_INVALID_ARRAY_INDEX);
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
