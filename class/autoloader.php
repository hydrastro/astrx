<?php
class Autoloader {
	public function __construct() {
		spl_autoload_register(array($this, "classAutoload"));
	}

	/**
	 * Class autoloader function.
	 * This function auto-loads the project's classes among their configs
	 * (language files, constants, configuration variables).
	 *
	 * @param $class
	 */
	function classAutoload($class) {
		$class_dir = (strpos(strtolower($class), "controller")) ?
			CONTROLLER_DIR : CLASS_DIR;
		$class = toSnakeCase($class);
		$class_file = "$class_dir$class.php";
		if(file_exists($class_file)) {
			require_once($class_file);
		}
	}
}
