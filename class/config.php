<?php
class Config {
	/**
	 * @var array $_CONF Config array.
	 */
	private $_CONF;
	
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
	 *
	 * @throws \Exception
	 */
	public function addConfigArray($array) {
		if(is_array($array)) {
			$this->_CONF = array_merge($this->_CONF, $array);
		}
		throw new Exception(ERROR_INVALID_ARRAY);
	}
	
	/**
	 * Load Config File.
	 * Loads a configuration file and merges it into the current configuration
	 * array.
	 *
	 * @param $config_file
	 *
	 * @throws \Exception
	 */
	public function loadConfigFile($config_file) {
		if(!is_string($config_file)) {
			throw new Exception(ERROR_INVALID_FILE_NAME);
		}
		if(!file_exists($config_file)) {
			throw new Exception(ERROR_FILE_NOT_FOUND);
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
	 * @throws \Exception
	 */
	public function getConfig($config_name, $class_name = null) {
		if(!is_string($config_name)) {
			throw new Exception(ERROR_INVALID_ARRAY_INDEX);
		}
		if($class_name !== null) {
			if(array_key_exists($class_name, $this->_CONF) &&
			   array_key_exists($config_name, $this->_CONF[$class_name])) {
				return $this->_CONF[$class_name][$config_name];
			}
			throw new Exception(ERROR_INVALID_ARRAY_INDEX);
		}
		
		if(array_key_exists($config_name, $this->_CONF)) {
			return $this->_CONF[$config_name];
		}
		throw new Exception(ERROR_INVALID_ARRAY_INDEX);
	}
}
