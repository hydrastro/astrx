<?php
class ErrorHandler {
	/**
	 * @var array classes Classes.
	 */
	private $classes = array();
	/**
	 * @var array $messages Messages array.
	 */
	public $messages = array();
	/**
	 * @var array $exceptions Exceptions (objects).
	 */
	protected $exceptions = array();

	/**
	 * ErrorHandler constructor.
	 */
	public function __construct() {
		if(DEBUG_MODE) {
			ini_set("display_errors", 1);
			ini_set("display_startup_errors", 1);
			error_reporting(E_ALL);
		}
		set_error_handler(array($this, "errorHandler"));
		set_exception_handler(array($this, "exceptionsHandler"));
		register_shutdown_function(array($this, "shutdownHandler"));
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
		$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
		                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
		                          "text" => $e->getMessage());
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
		$error_code = (defined("HTTP_INTERNAL_SERVER_ERROR")) ?
			HTTP_INTERNAL_SERVER_ERROR : 500;
		$message_level = (defined("MESSAGE_LEVEL_ERROR")) ?
			MESSAGE_LEVEL_ERROR : 0;
		$this->messages[] = array("level" => $message_level,
		                          "http_status_code" => $error_code,
		                          "text" => $e->getMessage());
	}

	/**
	 * Shutdown handler.
	 * Called when a script dies, either naturally or due to a fatal error,
	 * this function handles and displays possible occurred errors and exceptions.
	 * It's registered with register_shutdown_function.
	 */
	public function shutdownHandler() {
		$exceptions = $this->getExceptions();
		$messages = $this->getMessages();
		if(DEBUG_MODE && (!empty($exceptions) || !empty($messages))) {
			$failsafe = TEMPLATE_DIR . "failsafe.php";
			if(file_exists($failsafe)) {
				require($failsafe);
			} else {
				/* Oh, no! */
				echo "<h1>Error</h1><pre>";
				print_r($messages);
				print_r($exceptions);
			}
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
		$error_message = (defined("ERROR_INVALID_OBJECT")) ?
			ERROR_INVALID_OBJECT : "Invalid object.";
		$e = new Exception($error_message);
		$this->exceptions[] = $e;
		$error_code = (defined("HTTP_INTERNAL_SERVER_ERROR")) ?
			HTTP_INTERNAL_SERVER_ERROR : 500;
		$message_level = (defined("MESSAGE_LEVEL_ERROR")) ?
			MESSAGE_LEVEL_ERROR : 0;
		$this->messages[] = array("level" => $message_level,
		                          "http_status_code" => $error_code,
		                          "text" => $e->getMessage());
	}

	/**
	 * Remove Class.
	 * Removes a class from the class array.
	 *
	 * @param $class
	 */
	public function removeClass($class) {
		if(is_object($class) &&
		   ($key = array_search($class, $this->classes, true)) !== false) {
			unset($this->classes[$key]);

			return;
		}
		$error_message = (defined("ERROR_INVALID_ARRAY_INDEX")) ?
			ERROR_INVALID_ARRAY_INDEX : "Invalid array index.";
		$e = new Exception($error_message);
		$this->exceptions[] = $e;
		$error_code = (defined("HTTP_INTERNAL_SERVER_ERROR")) ?
			HTTP_INTERNAL_SERVER_ERROR : 500;
		$message_level = (defined("MESSAGE_LEVEL_ERROR")) ?
			MESSAGE_LEVEL_ERROR : 0;
		$this->messages[] = array("level" => $message_level,
		                          "http_status_code" => $error_code,
		                          "text" => $e->getMessage());
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
			if(property_exists($class, "messages") &&
			   is_array($class->messages)) {
				foreach($class->messages as $message) {
					$messages[] = $message;
				}
			}
		}

		return $messages;
	}
}
