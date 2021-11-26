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

	const TOKENIZER_STATE_TAG = 0;
	const TOKENIZER_STATE_TEXT = 1;

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


	public function tokenizeTemplate($template) {
		if(!is_string($template)) {
			return null;
		}
		$tokenized = array();
		$state = self::TOKENIZER_STATE_TEXT;
		$open_tag="{{{"; $close_tag="}}}";
		$buffer= "";
		$type = self::TOKEN_TYPE_TEXT;
		$template_length=strlen($template);
		$unclosed_token = false;
		for($i = 1; $i < $template_length; $i++) {
			// trim $buffer, split in 2 (whitespace) and set
			$i--;
			$open_tag_length = strlen($open_tag);
			$close_tag_length = strlen($close_tag);
			if(substr($template, $i, $close_tag_length) == $close_tag){
				$unclosed_token = false;
				$type=self::TOKEN_TYPE_TEXT;
				$i += $close_tag_length;
			}
			if(substr($template, $i, $open_tag_length) == $open_tag){
				if($unclosed_token) {
					// unclosed tag
				}
				$unclosed_token = true;
				if(in_array($template[$i + $open_tag_length], self::TOKEN_TYPES)) {
					$type = $template[$i + $open_tag_length];
					$i +=1;
				} else {
					$type = self::TOKEN_TYPE_VAR;
				}
				$i+=$open_tag_length;
			}
			while(!(substr($template, $i, $close_tag_length) == $close_tag) && !(substr($template, $i, $open_tag_length) == $open_tag) && $i < $template_length){
				$buffer.=$template[$i];
				$i++;
			}
			if($unclosed_token){
				$buffer=trim($buffer);
				if($type == self::TOKEN_TYPE_CHANGE_TAGS) {
					$tags = explode(" ", $buffer);
					if(count($tags) != 2){
						echo "malformed tag change";
						return;
					}
					$open_tag = $tags[0];
					$close_tag = $tags[1];
				}
			}
			$tokenized[] = array(
				"type"=>$type,
				"value"=>$buffer
			);
			echo "'".$buffer . "'\n";
			$buffer="";
		}
		if($unclosed_token) {
			// unclosed tag
		}

		return $tokenized;
	}

	public function parseTemplate($tokenized) {
		if(!is_array($tokenized)) {
			return;
		}
		$AST=array();
		$branch = array();
		$open_branches = array();
		for($i = count($tokenized) - 1; $i >=0; $i--){
			$type = $tokenized[$i]["type"];
			if($type == self::TOKEN_TYPE_LOOP_END) {
				$open_branches[] = $tokenized[$i]["value"];
				continue;
			}
			if($type == self::TOKEN_TYPE_LOOP_START || $type==self::TOKEN_TYPE_INVERTED_LOOP_START) {
				if(end($open_branches) != $tokenized[$i]["value"]) {
					echo "MISMATCH";
				}
				array_pop($open_branches);
				$branch=array($branch);
			}

			$branch[] = $tokenized[$i];

			if(empty($open_branches)){
				$AST = array_merge($AST, $branch);
				$branch = array();
			}
		}
		print_r($AST);
		return $AST;
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

		$this->parse($this->tokenize($template));


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
