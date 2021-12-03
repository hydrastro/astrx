<?php
class ResultMessage {
	private $http_status_code;
	private $message_level;
	private $message_text;

	const MESSAGE_LEVEL_ERROR = 0;
	const MESSAGE_LEVEL_WARNING = 1;
	const MESSAGE_LEVEL_INFO = 2;

	const MESSAGE_LEVELS=array(self::MESSAGE_LEVEL_ERROR, self::MESSAGE_LEVEL_WARNING, self::MESSAGE_LEVEL_INFO);

	public function __construct($http_status_code, $message_level, $message_text){

		$this->http_status_code=$http_status_code;
		$this->message_level=$message_level;
		$this->message_text=$message_text;
	}
}