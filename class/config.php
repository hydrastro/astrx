<?php
class Config {
	/**
	 * @var array $configuration Config array.
	 */
	private $configuration;
	/**
	 * @var array $messages Errors array.
	 */
	public $messages = array();
	/**
	 * @var array $exceptions Exceptions objects array.
	 */
	public $exceptions = array();

	/**
	 * Config Constructor.
	 */
	public function __construct() {
		$this->configuration = require(CONFIG_DIR . "config.php");
		$lang = $this->getConfig("language");
		require(LANG_DIR . "$lang.php");
	}

	/**
	 * Add Config Array.
	 * Merges a given configuration array with the existing configuration array.
	 *
	 * @param $array
	 *
	 * @return bool
	 */
	public function addConfigArray($array) {
		if(is_array($array)) {
			$this->configuration = array_merge($this->configuration, $array);

			return true;
		}
		$e = new Exception(ERROR_INVALID_ARRAY);
		$this->exceptions[] = $e;
		$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
		                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
		                          MESSAGE_TEXT => $e->getMessage());

		return false;
	}

	/**
	 * Add Specific Config.
	 * Add a value to a specific index in the config array.
	 *
	 * @param $index
	 * @param $value
	 *
	 * @return bool
	 */
	public function addSpecificConfig($index, $value) {
		if(!is_string($index)) {
			$e = new Exception(ERROR_INVALID_ARRAY_INDEX);
			$this->exceptions[] = $e;
			$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
			                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
			                          MESSAGE_TEXT => $e->getMessage());

			return false;
		}
		$this->configuration[$index] = $value;

		return true;
	}

	/**
	 * Load Config.
	 * Loads a module configuration if there is any set.
	 *
	 * @param $class
	 */
	function loadConfig($class) {
		$class = toSnakeCase($class);
		$class_path = CONFIG_DIR . "$class.config.php";
		$this->loadConfigFile($class_path);
	}

	/**
	 * Load Config File.
	 * Loads a configuration file if exists and merges it into the current
	 * configuration array.
	 *
	 * @param      $config_file
	 * @param bool $handle_not_found_exception
	 *
	 * @return bool
	 */
	public function loadConfigFile($config_file,
		$handle_not_found_exception = false) {
		if(!is_string($config_file)) {
			$e = new Exception(ERROR_INVALID_FILE_NAME);
			$this->exceptions[] = $e;
			$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
			                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
			                          MESSAGE_TEXT => $e->getMessage());

			return false;
		}
		if(!file_exists($config_file)) {
			if($handle_not_found_exception) {
				$e = new Exception(ERROR_NONEXISTENT_FILE);
				$this->exceptions[] = $e;
				$this->messages[] = array(MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
				                          MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
				                          MESSAGE_TEXT => $e->getMessage());
			}

			return false;
		}
		$this->configuration = array_merge($this->configuration,
			require($config_file));

		return true;
	}

	/**
	 * Get Config.
	 * Returns a config which may be specific for a class.
	 * It directly maps to $configuration[$class_name][$config_name]
	 * or $configuration[$config_name] if no class is provided.
	 *
	 * @param      $config_name
	 * @param null $class_name
	 *
	 * @return mixed|null
	 */
	public function getConfig($config_name, $class_name = null) {
		if(is_string($config_name)) {
			if($class_name !== null &&
			   array_key_exists($class_name, $this->configuration) &&
			   array_key_exists($config_name,
				   $this->configuration[$class_name])) {
				return $this->configuration[$class_name][$config_name];
			}
			if(array_key_exists($config_name, $this->configuration)) {
				return $this->configuration[$config_name];
			}
		}
		$error_message = (defined("ERROR_INVALID_ARRAY_INDEX")) ?
			ERROR_INVALID_ARRAY_INDEX : "Invalid array index.";
		$e = new Exception($error_message);
		$this->exceptions[] = $e;
		$error_code = HTTP_INTERNAL_SERVER_ERROR;
		$message_level = MESSAGE_LEVEL_ERROR;
		$this->messages[] = array(MESSAGE_LEVEL => $message_level,
		                          MESSAGE_HTTP_STATUS => $error_code,
		                          MESSAGE_TEXT => $e->getMessage());

		return null;
	}
}
