<?php
class Request {
	/**
	 * Request constructor.
	 */
	public function __construct() {
	}

	/**
	 * Get.
	 * Returns a $_GET value.
	 *
	 * @param $key
	 *
	 * @return mixed|null
	 */
	public function get($key) {
		if(isset($_GET[$key])) {
			return $_GET[$key];
		}

		return null;
	}

	/**
	 * Post.
	 * Returns a $_POST value.
	 *
	 * @param $key
	 *
	 * @return mixed|null
	 */
	public function post($key) {
		if(isset($_POST[$key])) {
			return $_POST[$key];
		}

		return null;
	}

	/**
	 * Files.
	 * Returns a $_FILES value.
	 *
	 * @param $key
	 *
	 * @return mixed|null
	 */
	public function files($key) {
		if(isset($_FILES[$key])) {
			return $_FILES[$key];
		}

		return null;
	}

	/**
	 * Request.
	 * Returns a $_REQUEST value.
	 *
	 * @param $key
	 *
	 * @return mixed|null
	 */
	public function request($key) {
		if(isset($_REQUEST[$key])) {
			return $_REQUEST[$key];
		}

		return null;
	}
}
