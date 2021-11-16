<?php
class ErrorHandler {
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
	 * Exceptions Handler
	 * Stores exceptions in the class instance for future display.
	 *
	 * @param $e
	 */
	public function exceptionsHandler($e) {
		$this->exceptions[] = $e;
	}
	
	/**
	 * Error Handler
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
	 * Shutdown handler
	 * Called when a script dies, handles fatal errors.
	 * It's registered with register_shutdown_function.
	 */
	public function shutdownHandler() {
		if(isset($this->exceptions) && !empty($this->exceptions)) {
			// Here we may want something nicer
			echo "<h1>Error</h1><pre>";
			print_r($this->exceptions);
		}
	}
}
