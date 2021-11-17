<?php
class Autoloader {
	/**
	 * @var \Config $config Config class.
	 */
	private $config;
	/**
	 * @var array $errors Errors string array.
	 */
	public $errors = array();
	/**
	 * @var array $exceptions Exceptions objects array.
	 */
	public $exceptions = array();
	/**
	 * Autoloader constructor.
	 *
	 * @param \Config $config
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
		$class = ltrim(strtolower(preg_replace('/[A-Z]([A-Z](?![a-z]))*/',
			'_$0',
			$class)),
			'_');
		$this->loadLang($class);
		$this->loadConfig($class);
		require_once("$class_dir$class.php");
	}
	
	/**
	 * Load Language.
	 * Loads a module language if there is any set.
	 *
	 * @param $class
	 */
	function loadLang($class) {
		try {
			$lang = $this->config->getConfig("language");
		} catch(Exception $e) {
			$this->errors[] = $e->getMessage();
			$this->exceptions = $e;
		}
		$lang_file = LANG_DIR . "$class.$lang.php";
		if(file_exists($lang_file)) {
			require_once(LANG_DIR . "$class.$lang.php");
		}
	}
	
	/**
	 * Load Config.
	 * Loads a module configuration if there is any set.
	 *
	 * @param $class
	 */
	function loadConfig($class) {
		$class_path = CONFIG_DIR . "$class.conf.php";
		try {
			$this->config->loadConfigFile($class_path);
		} catch(Exception $e) {
			$this->errors[] = $e->getMessage();
			$this->exceptions = $e;
		}
	}
}
