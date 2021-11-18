<?php
class ErrorHandler {
	/**
	 * @var array classes Classes.
	 */
	private $classes = array();
	/**
	 * @var array $errors Errors array. Format: array(http status code, message)
	 */
	public $errors = array();
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
		$this->errors[] = array(HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
	}

	/**
	 * Error Handler.
	 * Throws errors as exceptions.
	 *
	 * @param $errno
	 * @param $errstr
	 * @param $errfile
	 * @param $errline
	 */
	public function errorHandler($errno, $errstr, $errfile, $errline) {
		$level = error_reporting();
		if(($level & $errno) === 0) {
			return;
		}
		$e = new ErrorException($errstr, 0, $errno, $errfile, $errline);
		$this->exceptions[] = $e;
		$error_code = (defined("HTTP_INTERNAL_SERVER_ERROR")) ? HTTP_INTERNAL_SERVER_ERROR : 500;
		$this->errors[] = array($error_code, $e->getMessage());
	}

	/**
	 * Shutdown handler.
	 * Called when a script dies, either naturally or due a fatal error,
	 * this function handles and displays possible occurred errors and exceptions.
	 * It's registered with register_shutdown_function.
	 */
	public function shutdownHandler() {
		$exceptions = $this->getExceptions();
		$errors = $this->getErrors();
		$messages = $this->getMessages();
		if(DEBUG_MODE && (!empty($exceptions) || !empty($errors) || !empty($messages))) {
			// Here we may want something nicer
			echo "<h1>Error</h1><pre>";
			print_r($messages);
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
		$error_message = (defined("ERROR_INVALID_OBJECT")) ? ERROR_INVALID_OBJECT : "Invalid object.";
		$e = new Exception($error_message);
		$this->exceptions[] = $e;
		$error_code = (defined("HTTP_INTERNAL_SERVER_ERROR")) ? HTTP_INTERNAL_SERVER_ERROR : 500;
		$this->errors[] = array($error_code, $e->getMessage());
	}

	/**
	 * Remove Class.
	 * Removes a class from the class array.
	 *
	 * @param $class
	 */
	public function removeClass($class) {
		if(is_object($class) && ($key = array_search($class, $this->classes, true)) !== false) {
			unset($this->classes[$key]);

			return;
		}
		$error_message = (defined("ERROR_INVALID_ARRAY_INDEX")) ? ERROR_INVALID_ARRAY_INDEX : "Invalid array index.";
		$e = new Exception($error_message);
		$this->exceptions[] = $e;
		$error_code = (defined("HTTP_INTERNAL_SERVER_ERROR")) ? HTTP_INTERNAL_SERVER_ERROR : 500;
		$this->errors[] = array($error_code, $e->getMessage());
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
	/**
	 * Get Messages.
	 * Retrieves and returns the messages of all the classes handled.
	 * @return array
	 */
	public function getMessages() {
		$messages = array();
		if(empty($this->classes)) {
			return array();
		}
		foreach($this->classes as $class) {
			if(property_exists($class, 'messages') && is_array($class->messages)) {
				foreach($class->messages as $message) {
					$messages[] = $message;
				}
			}
		}

		return $messages;
	}
}
