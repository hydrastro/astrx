<?php
class MessageHandler {
	/**
	 * @var array classes Classes.
	 */
	private $classes = array();
	/**
	 * @var array $errors Errors string array.
	 */
	public $errors = array();
	/**
	 * @var array $messages Messages string array.
	 */
	protected $messages = array();
	/**
	 * @var array $exceptions Exceptions objects array.
	 */
	public $exceptions = array();
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
	public function getErrors() {
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
