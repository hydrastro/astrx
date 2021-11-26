<?php
class TemplateEngine {
	const PARSE_MODE_PHP = 0;
	const PARSE_MODE_TEMPLATE = 1;
	const PARSE_MODES = array(self::PARSE_MODE_PHP, self::PARSE_MODE_TEMPLATE);
	/**
	 * @var array $templates Templates array.
	 */
	private $templates = array();
	/**
	 * @var array $args Arguments array.
	 */
	private $args = array();
	/**
	 * @var array $messages Messages array.
	 */
	public $messages = array();
	/**
	 * @var array $exceptions Exceptions objects array.
	 */
	public $exceptions = array();
	/**
	 * @var int $parse_mode Template parse mode.
	 */
	private $parse_mode = self::PARSE_MODE_PHP;

	/**
	 * Set Parse Mode.
	 * Sets the parse mode.
	 *
	 * @param int $parse_mode
	 */
	public function setParseMode($parse_mode) {
		if(!in_array($parse_mode, self::PARSE_MODES)) {
			$e = new Exception(ERROR_INVALID_PARSE_MODE);
			$this->exceptions[] = $e;
			$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
			                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
			                          "text" => $e->getMessage());

			return;
		}
		$this->parse_mode = $parse_mode;
	}

	/**
	 * Get Parse Mode.
	 * Returns the parse mode.
	 * @return int
	 */
	public function getParseMode() {
		return $this->parse_mode;
	}

	/**
	 * Get Configuration Methods.
	 * Returns the methods that will be called by the injector.
	 * @return string[]
	 */
	public function getConfigurationMethods() {
		return array("getParseMode");
	}

	/**
	 * Load Template.
	 * Loads a template file into the $templates array.
	 *
	 * @param $template
	 *
	 * @return bool
	 */
	public function loadTemplate($template) {
		if(!file_exists($template)) {
			$e = new Exception(ERROR_TEMPLATE_FILE_NOT_FOUND);
			$this->exceptions[] = $e;
			$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
			                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
			                          "text" => $e->getMessage());

			return false;
		}
		$this->templates[$template]
			= file_get_contents(TEMPLATE_DIR . $template . "php");

		return true;
	}

	/**
	 * Add Global Args.
	 * Adds pragmas that will overwrite the template arguments.
	 *
	 * @param $args
	 *
	 * @return bool
	 */
	public function addGlobalArgs($args) {
		if(!is_array($args)) {
			$e = new Exception(ERROR_INVALID_ARRAY);
			$this->exceptions[] = $e;
			$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
			                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
			                          "text" => $e->getMessage());

			return false;
		}
		$this->args = array_merge($this->args, $args);

		return true;
	}

	/**
	 * Get Template Class Name.
	 *
	 * @param $template
	 *
	 * @return string
	 */
	public function getTemplateClassName($template) {
		return "Template" . ucfirst($template);
	}



	public function tokenizeTemplate($template) {

	}

	public function parseTemplate($template) {

	}

	public function compileTemplate($template, $args) {
		if(!empty($args) && !is_array($args)) {
			$e = new Exception(ERROR_INVALID_ARRAY);
			$this->exceptions[] = $e;
			$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
			                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
			                          "text" => $e->getMessage());

			return null;
		}
		if(!isset($this->templates[$template])) {
			$this->loadTemplate($template);
		}
		$raw_template = $this->templates[$template];
		$template_class_name = $this->getTemplateClassName($template);

		$template_class_variables = "";
		$template_render_body = "";

		$template_code = "";
	}

	/**
	 * Render Template.
	 * Compiles and gets a processed template.
	 *
	 * @param $template
	 * @param $args
	 *
	 * @return string|null
	 */
	public function renderTemplate($template, $args) {
		return null;
	}

	/*
	function foo() {
		$buffer = '';
		if(!empty($value)) {
			$values = $this->isIterable($value) ? $value : array($value);
			foreach($values as $value) {
				$value = $value['index_name'];
				$buffer .= htmlspecialchars($value, 2, 'UTF-8');
			}
		}

		return $buffer;
	}
	*/






	/**
	 * Render.
	 * Renders a template: either a PHP template or a template that needs to be
	 * compiled.
	 *
	 * @param      $template
	 * @param      $args
	 * @param null $parse_mode
	 *
	 * @return string|null
	 */
	public function render($template, $args, $parse_mode = null) {
		if(!empty($parse_mode)) {
			if(!in_array($parse_mode, self::PARSE_MODES)) {
				$e = new Exception(ERROR_INVALID_PARSE_MODE);
				$this->exceptions[] = $e;
				$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
				                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
				                          "text" => $e->getMessage());
			}
		} else {
			$parse_mode = $this->getParseMode();
		}
		switch($parse_mode) {
			default:
			case self::PARSE_MODE_PHP:
				return $this->renderPHP($template, $args);
			case self::PARSE_MODE_TEMPLATE:
				return $this->renderTemplate($template, $args);
		}
	}

	/**
	 * Render PHP.
	 * Renders a PHP template with output buffering functions.
	 *
	 * @param $template
	 * @param $args
	 *
	 * @return string|null
	 */
	public function renderPHP($template, $args) {
		extract($args);
		ob_start();
		require(TEMPLATE_DIR . $template);
		$content = ob_get_clean();

		return !empty($content) ? $content : null;
	}

	public function removeTemplate() {
	}

	public function removeAll() {
	}
}
