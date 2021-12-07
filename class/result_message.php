<?php
class ResultMessage {
	const MESSAGE_LEVEL_ERROR = 0;
	const MESSAGE_LEVEL_WARNING = 1;
	const MESSAGE_LEVEL_INFO = 2;
	const MESSAGE_LEVELS
		= array(self::MESSAGE_LEVEL_ERROR,
		        self::MESSAGE_LEVEL_WARNING,
		        self::MESSAGE_LEVEL_INFO);
	/**
	 * @var int $level Level.
	 */
	private $level;
	/**
	 * @var int $status_code HTTP status code.
	 */
	private $status_code;
	/**
	 * @var string $text Message text.
	 */
	private $text;

	/**
	 * ResultMessage constructor.
	 *
	 * @param $level
	 * @param $status_code
	 * @param $text
	 */
	public function __construct($level, $status_code, $text) {
		if(!in_array($level, self::MESSAGE_LEVELS)) {
			return new ResultMessage(self::MESSAGE_LEVEL_ERROR,
				HTTP_INTERNAL_SERVER_ERROR,
				"error message here");
			// Error text is recursive.
		}
		//if(!in_array($status_code, Response::STATUS_CODES)){
		//  	return new ResultMessage(self::MESSAGE_LEVEL_ERROR,
		//		HTTP_INTERNAL_SERVER_ERROR, "error message here");
		//}
		if(!is_string($text)) {
			return new ResultMessage(self::MESSAGE_LEVEL_ERROR,
				HTTP_INTERNAL_SERVER_ERROR,
				"error message here");
		}
		$this->level = $level;
		$this->status_code = $status_code;
		$this->text = $text;
	}

	/**
	 * @return int|null
	 */
	public function getLevel() {
		return $this->level;
	}

	/**
	 * @return int|null
	 */
	public function getStatusCode() {
		return $this->status_code;
	}

	/**
	 * @return string|null
	 */
	public function getText() {
		return $this->text;
	}
}
