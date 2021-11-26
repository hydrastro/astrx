<?php
class Autoloader {
	/**
	 * @var Config $config Config class.
	 */
	private $config;

	/**
	 * Autoloader constructor.
	 *
	 * @param Config $config
	 */
	public function __construct(Config $config) {
		$this->config = $config;
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
		$this->loadLang($class);
		$this->config->loadConfig($class);
		require_once("$class_dir$class.php");
	}



	/**
	 * Load Language.
	 * Loads a module language if there is any set.
	 *
	 * @param $class
	 */
	function loadLang($class) {
		$lang = $this->config->getConfig("language");
		$class = toSnakeCase($class);
		$lang_file = LANG_DIR . "$class.$lang.php";
		if(file_exists($lang_file)) {
			require_once(LANG_DIR . "$class.$lang.php");
		}
	}
}
