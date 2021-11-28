<?php
class TemplateEngine {
	const PARSE_MODE_PHP = 0;
	const PARSE_MODE_TEMPLATE = 1;
	const PARSE_MODES = array(self::PARSE_MODE_PHP, self::PARSE_MODE_TEMPLATE);

	const TOKEN_TYPE_TEXT = "text";
	const TOKEN_TYPE_VAR = "var";
	const TOKEN_TYPE_UNESCAPED_VAR = "&";
	const TOKEN_TYPE_LOOP_START = "#";
	const TOKEN_TYPE_LOOP_END = "/";
	const TOKEN_TYPE_INVERTED_LOOP_START = "^";
	const TOKEN_TYPE_PARTIAL = ">";
	const TOKEN_TYPE_COMMENT = "!";
	const TOKEN_TYPE_CHANGE_TAGS = "=";
	/*
	 * Token external types are used as internal for practical purposes.
	 * TOKEN_TYPES contains types with prefixes.
	 */
	const TOKEN_TYPES = array(self::TOKEN_TYPE_CHANGE_TAGS,
		self::TOKEN_TYPE_COMMENT,self::TOKEN_TYPE_PARTIAL,self::TOKEN_TYPE_INVERTED_LOOP_START,
		self::TOKEN_TYPE_LOOP_END, self::TOKEN_TYPE_LOOP_END, self::TOKEN_TYPE_LOOP_START, self::TOKEN_TYPE_UNESCAPED_VAR);

	const TEMPLATE_OPEN_TAG = "{{";
	const TEMPLATE_CLOSE_TAG = "}}";

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
	 * Adds global args that will overwrite the template arguments.
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

	public function tokenizeTemplate($template_body) {
		if(!is_string($template_body)) {
			$e = new Exception(ERROR_INVALID_TEMPLATE);
			$this->exceptions[] = $e;
			$this->messages[] = array("level"=>MESSAGE_LEVEL_ERROR, "http_status_code" => HTTP_INTERNAL_SERVER_ERROR, "text"=>$e->getMessage());
			return null;
		}
		$tokenized = array();
		$open_tag=self::TEMPLATE_OPEN_TAG;
		$close_tag=self::TEMPLATE_CLOSE_TAG;
		$buffer= "";
		$type = self::TOKEN_TYPE_TEXT;
		$template_length=strlen($template_body);
		$unclosed_token = false;
		for($i = 1; $i < $template_length; $i++) {
			$i--;
			$close_tag_length = strlen($close_tag);
			if(substr($template_body, $i, $close_tag_length) == $close_tag){
				if($type == self::TOKEN_TYPE_CHANGE_TAGS) {
					$tags = explode(" ", $buffer);
					if(count($tags) != 2) {
						$e = new Exception(ERROR_MALFORMED_TAG_CHANGE);
						$this->exceptions[] = $e;
						$this->messages[] = array("level"=>MESSAGE_LEVEL_ERROR, "http_status_code" => HTTP_INTERNAL_SERVER_ERROR, "text"=>$e->getMessage());
						return null;
					}
					$open_tag = $tags[0];
					$close_tag = $tags[1];
				}
				$unclosed_token = false;
				$type=self::TOKEN_TYPE_TEXT;
				$i += $close_tag_length;
			}
			$open_tag_length = strlen($open_tag);
			$close_tag_length = strlen($close_tag);
			if(substr($template_body, $i, $open_tag_length) == $open_tag){
				if($unclosed_token) {
					$e = new Exception(ERROR_UNCLOSED_TOKEN);
					$this->exceptions[] = $e;
					$this->messages[] = array("level"=>MESSAGE_LEVEL_ERROR, "http_status_code" => HTTP_INTERNAL_SERVER_ERROR, "text"=>$e->getMessage());
				}
				$unclosed_token = true;
				if(in_array($template_body[$i + $open_tag_length], self::TOKEN_TYPES)) {
					$type = $template_body[$i + $open_tag_length];
					$i +=1;
				} else {
					$type = self::TOKEN_TYPE_VAR;
				}
				$i+=$open_tag_length;
			}
			$buffer = "";
			while(!(substr($template_body, $i,
						$close_tag_length) == $close_tag) && !(substr($template_body, $i, $open_tag_length) == $open_tag) && $i < $template_length){
				$buffer.=$template_body[$i];
				$i++;
			}
			if($unclosed_token) {
				$buffer = trim($buffer);
				if($type==self::TOKEN_TYPE_CHANGE_TAGS) {
					if(substr($buffer, -1) != self::TOKEN_TYPE_CHANGE_TAGS) {
						$e = new Exception(ERROR_MALFORMED_TAG_CHANGE);
						$this->exceptions[] = $e;
						$this->messages[] = array("level"=>MESSAGE_LEVEL_ERROR, "http_status_code" => HTTP_INTERNAL_SERVER_ERROR, "text"=>$e->getMessage());
						return null;
					}
					$buffer = rtrim($buffer,self::TOKEN_TYPE_CHANGE_TAGS);
				}
			}
			$tokenized[] = array(
				"type"=>$type,
				"value"=>$buffer
			);
		}
		if($unclosed_token) {
			$e = new Exception(ERROR_UNCLOSED_TOKEN);
			$this->exceptions[] = $e;
			$this->messages[] = array("level"=>MESSAGE_LEVEL_ERROR, "http_status_code" => HTTP_INTERNAL_SERVER_ERROR, "text"=>$e->getMessage());
		}

		return $tokenized;
	}

	public function parseTemplate($tokenized) {
		if(!is_array($tokenized)) {
			$e = new Exception(ERROR_INVALID_TOKENIZED_TEMPLATE);
			$this->exceptions[] = $e;
			$this->messages[] = array("level"=>MESSAGE_LEVEL_ERROR, "http_status_code" => HTTP_INTERNAL_SERVER_ERROR, "text"=>$e->getMessage());
			return null;
		}
		$AST=array();

		$branches = array();
		$branch_names = array();

		for($i = count($tokenized) - 1; $i >=0; $i--){
			if(!isset($tokenized[$i]["type"]) || !isset($tokenized[$i]["value"])) {
				$e = new Exception(ERROR_INVALID_TOKENIZED_TEMPLATE);
				$this->exceptions[] = $e;
				$this->messages[] = array("level"=>MESSAGE_LEVEL_ERROR, "http_status_code" => HTTP_INTERNAL_SERVER_ERROR, "text"=>$e->getMessage());
				return null;
			}

			$type = $tokenized[$i]["type"];
			$value = $tokenized[$i]["value"];

			if($type == self::TOKEN_TYPE_LOOP_END) {
				$index = count($AST);
				$AST[$index] = array();
				$branches[] = &$AST;
				$AST = &$AST[$index][0];
				$branch_names[]=$value;
			}
			if($type == self::TOKEN_TYPE_LOOP_START || $type==self::TOKEN_TYPE_INVERTED_LOOP_START) {
				if($value != end($branch_names)) {
					echo "ERROR";
				}
				$index=count($branches) - 1;
				if(!empty($branches[$index])) {
					$AST = &$branches[$index];
				} else {
					$AST=array($AST);
				}

				array_pop($branches);
				array_pop($branch_names);

			}
			$AST[]=$tokenized[$i];
		}
		return $AST;
	}

	public function writeCode($AST){
		$code = "";
		for($i=count($AST); $i >=0;$i--) {
			if(!isset($AST[$i]["type"]) || !isset($AST[$i]["value"])) {
				$e = new Exception(ERROR_INVALID_TOKENIZED_TEMPLATE);
				$this->exceptions[] = $e;
				$this->messages[] = array("level"=>MESSAGE_LEVEL_ERROR, "http_status_code" => HTTP_INTERNAL_SERVER_ERROR, "text"=>$e->getMessage());
				return null;
			}
			$value = $AST[$i]["value"];
			switch($AST[$i]["type"]) {
				default:
				case self::TOKEN_TYPE_TEXT:
					$code = '$buffer .= "' . $value . '";' . "\n$code";
					break;
				case self::TOKEN_TYPE_VAR:
					$code = '$buffer .= htmlspecialchars($this->' . $value . ");\n$code";
					break;
				case self::TOKEN_TYPE_UNESCAPED_VAR:
					$code = '$buffer .= $this->' . $value . ";\n$code";
					break;
				case self::TOKEN_TYPE_LOOP_START:
					break;
				case self::TOKEN_TYPE_LOOP_END:
					break;
				case self::TOKEN_TYPE_INVERTED_LOOP_START:
					break;
				case self::TOKEN_TYPE_PARTIAL:
					break;
				case self::TOKEN_TYPE_COMMENT:
					break;
				case self::TOKEN_TYPE_CHANGE_TAGS:
					break;
			}
		}
		return $code;
	}

	public function addCodeSection(){

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

		$template_body = $this->templates[$template];

		$AST = $this->parseTemplate($this->tokenizeTemplate($template_body));


		$template_class_name = $this->getTemplateClassName($template);
		$template_class_variables = ""; // easy, class variables
		$template_render_body = "";
		$template_functions = "";

		$template_code = "";



		return $template_code;
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
		//$this->compileTemplate();
		//eval
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
