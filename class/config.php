<?php
class Config {
	/**
	 * @var array $_CONF Config array.
	 */
	private $_CONF;
	/**
	 * @var array $errors Errors string array.
	 */
	public $errors = array();
	/**
	 * @var array $exceptions Exceptions objects array.
	 */
	public $exceptions = array();
	/**
	 * Config Constructor.
	 */
	public function __construct() {
		$this->_CONF = require(CONFIG_DIR . "config.php");
		$lang = $this->getConfig("language");
		require(LANG_DIR . "$lang.php");
	}
	
	/**
	 * Add Config Array.
	 * Merges a given configuration array with the existing configuration array.
	 *
	 * @param $array
	 */
	public function addConfigArray($array) {
		if(is_array($array)) {
			$this->_CONF = array_merge($this->_CONF, $array);
		}
		$e = new Exception(ERROR_INVALID_ARRAY);
		$this->exceptions[] = $e;
		$this->errors[] = $e->getMessage();
	}
	
	/**
	 * Load Config File.
	 * Loads a configuration file if exists and merges it into the current
	 * configuration array.
	 *
	 * @param      $config_file
	 * @param bool $handle_not_found_exception
	 */
	public function loadConfigFile($config_file, $handle_not_found_exception = false) {
		if(!is_string($config_file)) {
			$e = new Exception(ERROR_INVALID_FILE_NAME);
			$this->exceptions[] = $e;
			$this->errors[] = $e->getMessage();
			return;
		}
		if(!file_exists($config_file)) {
			if($handle_not_found_exception) {
				$e = new Exception(ERROR_INEXISTENT_FILE);
				$this->exceptions[] = $e;
				$this->errors[] = $e->getMessage();
			}
			return;
		}
		$this->_CONF = array_merge($this->_CONF, require($config_file));
	}
	
	/**
	 * Get Config.
	 * Returns a config which may be specific for a class.
	 *
	 * @param      $config_name
	 * @param null $class_name
	 *
	 * @return mixed
	 */
	public function getConfig($config_name, $class_name = null) {
		if(!is_string($config_name)) {
			$e = new Exception(ERROR_INVALID_ARRAY_INDEX);
			$this->exceptions[] = $e;
			$this->errors[] = $e->getMessage();
			return null;
		}
		if($class_name !== null) {
			if(array_key_exists($class_name, $this->_CONF) &&
			   array_key_exists($config_name, $this->_CONF[$class_name])) {
				return $this->_CONF[$class_name][$config_name];
			}
			$e = new Exception(ERROR_INVALID_ARRAY_INDEX);
			$this->exceptions[] = $e;
			$this->errors[] = $e->getMessage();
			return null;
		}
		
		if(array_key_exists($config_name, $this->_CONF)) {
			return $this->_CONF[$config_name];
		}
		$e = new Exception(ERROR_INVALID_ARRAY_INDEX);
		$this->exceptions[] = $e;
		$this->errors[] = $e->getMessage();
		return null;
	}
}
