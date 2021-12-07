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
		$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
		                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
		                          MESSAGE_TEXT => $e->getMessage());
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
		$error_code = HTTP_INTERNAL_SERVER_ERROR;
		$message_level = MESSAGE_LEVEL_ERROR;
		$this->messages[] = array(MESSAGE_LEVEL => $message_level,
		                          MESSAGE_HTTP_STATUS => $error_code,
		                          MESSAGE_TEXT => $e->getMessage());
	}

	/**
	 * Shutdown handler.
	 * Called when a script dies, either naturally or due to a fatal error,
	 * this function handles and displays possible occurred errors and exceptions.
	 * It's registered with register_shutdown_function.
	 */
	public function shutdownHandler() {
		$exceptions = $this->getExceptions();
		if(DEBUG_MODE && (!empty($exceptions))) {
			http_response_code(HTTP_INTERNAL_SERVER_ERROR);
			$failsafe = TEMPLATE_DIR . "failsafe.php";
			if(file_exists($failsafe)) {
				require($failsafe);
			} else {
				echo "<h1>Error</h1><pre>";
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
		$error_message = ERROR_INVALID_OBJECT;
		$e = new Exception($error_message);
		$this->exceptions[] = $e;
		$error_code = HTTP_INTERNAL_SERVER_ERROR;
		$message_level = MESSAGE_LEVEL_ERROR;
		$this->messages[] = array(MESSAGE_LEVEL => $message_level,
		                          MESSAGE_HTTP_STATUS => $error_code,
		                          MESSAGE_TEXT => $e->getMessage());
	}

	/**
	 * Remove Class.
	 * Removes a class from the class array.
	 *
	 * @param $class
	 *
	 * @return bool
	 */
	public function removeClass($class) {
		if(is_object($class) &&
		   ($key = array_search($class, $this->classes, true)) !== false) {
			unset($this->classes[$key]);

			return true;
		}
		$error_message = ERROR_INVALID_ARRAY_INDEX;
		$e = new Exception($error_message);
		$this->exceptions[] = $e;
		$error_code = HTTP_INTERNAL_SERVER_ERROR;
		$message_level = MESSAGE_LEVEL_ERROR;
		$this->messages[] = array(MESSAGE_LEVEL => $message_level,
		                          MESSAGE_HTTP_STATUS => $error_code,
		                          MESSAGE_TEXT => $e->getMessage());

		return false;
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
}
