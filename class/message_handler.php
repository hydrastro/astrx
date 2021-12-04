<?php
class MessageHandler {
	/**
	 * @var array classes Classes.
	 */
	private $classes = array();
	/**
	 * @var array $messages Messages array.
	 */
	public $messages = array();
	/**
	 * @var array $exceptions Exceptions array.
	 */
	public $exceptions = array();

	/**
	 * MessageHandler constructor.
	 */
	public function __construct() {
		$this->classes[] = $this;
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
