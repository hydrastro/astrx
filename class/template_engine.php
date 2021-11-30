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
	const TOKEN_TYPES
		= array(self::TOKEN_TYPE_CHANGE_TAGS,
		        self::TOKEN_TYPE_COMMENT,
		        self::TOKEN_TYPE_PARTIAL,
		        self::TOKEN_TYPE_INVERTED_LOOP_START,
		        self::TOKEN_TYPE_LOOP_END,
		        self::TOKEN_TYPE_LOOP_END,
		        self::TOKEN_TYPE_LOOP_START,
		        self::TOKEN_TYPE_UNESCAPED_VAR);
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
		$template_file = TEMPLATE_DIR . $template . ".php";
		if(!file_exists($template_file)) {
			$e = new Exception(ERROR_TEMPLATE_FILE_NOT_FOUND);
			$this->exceptions[] = $e;
			$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
			                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
			                          "text" => $e->getMessage());

			return false;
		}
		$this->templates[$template]
			= file_get_contents($template_file);

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

	public function tokenizeTemplate($template_body) {
		if(!is_string($template_body)) {
			$e = new Exception(ERROR_INVALID_TEMPLATE);
			$this->exceptions[] = $e;
			$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
			                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
			                          "text" => $e->getMessage());

			return null;
		}
		$tokenized = array();
		$open_tag = self::TEMPLATE_OPEN_TAG;
		$close_tag = self::TEMPLATE_CLOSE_TAG;
		$buffer = "";
		$type = self::TOKEN_TYPE_TEXT;
		$template_length = strlen($template_body);
		$unclosed_token = false;
		for($i = 1; $i < $template_length; $i++) {
			$i--;
			$close_tag_length = strlen($close_tag);
			if(substr($template_body, $i, $close_tag_length) == $close_tag) {
				if($type == self::TOKEN_TYPE_CHANGE_TAGS) {
					$tags = explode(" ", $buffer);
					if(count($tags) != 2) {
						$e = new Exception(ERROR_MALFORMED_TAG_CHANGE);
						$this->exceptions[] = $e;
						$this->messages[]
							= array("level" => MESSAGE_LEVEL_ERROR,
							        "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
							        "text" => $e->getMessage());

						return null;
					}
					$open_tag = $tags[0];
					$close_tag = $tags[1];
				}
				$unclosed_token = false;
				$type = self::TOKEN_TYPE_TEXT;
				$i += $close_tag_length;
			}
			$open_tag_length = strlen($open_tag);
			$close_tag_length = strlen($close_tag);
			if(substr($template_body, $i, $open_tag_length) == $open_tag) {
				if($unclosed_token) {
					$e = new Exception(ERROR_UNCLOSED_TOKEN);
					$this->exceptions[] = $e;
					$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
					                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
					                          "text" => $e->getMessage());
				}
				$unclosed_token = true;
				if(in_array($template_body[$i + $open_tag_length],
					self::TOKEN_TYPES)) {
					$type = $template_body[$i + $open_tag_length];
					$i += 1;
				} else {
					$type = self::TOKEN_TYPE_VAR;
				}
				$i += $open_tag_length;
			}
			$buffer = "";
			while(!(substr($template_body,
						$i,
						$close_tag_length) == $close_tag) &&
			      !(substr($template_body, $i, $open_tag_length) ==
			        $open_tag) &&
			      $i < $template_length) {
				$buffer .= $template_body[$i];
				$i++;
			}
			if($unclosed_token) {
				$buffer = trim($buffer);
				if($type == self::TOKEN_TYPE_CHANGE_TAGS) {
					if(substr($buffer, -1) != self::TOKEN_TYPE_CHANGE_TAGS) {
						$e = new Exception(ERROR_MALFORMED_TAG_CHANGE);
						$this->exceptions[] = $e;
						$this->messages[]
							= array("level" => MESSAGE_LEVEL_ERROR,
							        "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
							        "text" => $e->getMessage());

						return null;
					}
					$buffer = rtrim($buffer, self::TOKEN_TYPE_CHANGE_TAGS);
				}
			}
			if(empty($buffer)) {
				continue;
			}
			$tokenized[] = array("type" => $type,
			                     "value" => $buffer);
		}
		if($unclosed_token) {
			$e = new Exception(ERROR_UNCLOSED_TOKEN);
			$this->exceptions[] = $e;
			$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
			                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
			                          "text" => $e->getMessage());
		}

		return $tokenized;
	}

	public function parseTemplate($tokenized) {
		if(!is_array($tokenized)) {
			$e = new Exception(ERROR_INVALID_TOKENIZED_TEMPLATE);
			$this->exceptions[] = $e;
			$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
			                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
			                          "text" => $e->getMessage());

			return null;
		}
		$AST = array();
		$branches = array();
		$branch_names = array();
		for($i = count($tokenized) - 1; $i >= 0; $i--) {
			if(!isset($tokenized[$i]["type"]) ||
			   !isset($tokenized[$i]["value"])) {
				$e = new Exception(ERROR_INVALID_TOKENIZED_TEMPLATE);
				$this->exceptions[] = $e;
				$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
				                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
				                          "text" => $e->getMessage());

				return null;
			}
			$type = $tokenized[$i]["type"];
			$value = $tokenized[$i]["value"];

			if($type == self::TOKEN_TYPE_LOOP_END) {
				$index = count($AST);
				$AST[$index] = array();
				$branches[] = &$AST;
				$AST = &$AST[$index];
				$branch_names[] = $value;
			}
			if($type == self::TOKEN_TYPE_LOOP_START ||
			   $type == self::TOKEN_TYPE_INVERTED_LOOP_START) {
				if($value != end($branch_names)) {
					$e = new Exception(ERROR_LOOP_TOKEN_MISMATCH);
					$this->exceptions[] = $e;
					$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
					                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
					                          "text" => $e->getMessage());

					return null;
				}
				$index = count($branches) - 1;
				if(!empty($branches[$index])) {
					$AST = &$branches[$index];
				} else {
					$AST = array($AST);
				}

				array_pop($branches);
				array_pop($branch_names);
			}
			$AST[] = $tokenized[$i];
		}
		if(!empty($branch_names)) {
			$e = new Exception(ERROR_UNCLOSED_LOOP_TOKEN);
			$this->exceptions[] = $e;
			$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
			                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
			                          "text" => $e->getMessage());

			return null;
		}

		return $AST;
	}

	public function writeCode($AST,
		$args,
		$loop_parents = null,
		&$functions_code = null,
		$iteration_number = 0) {
		$code = '';
		if($functions_code === null) {
			$functions_code = array();
		}
		if(!empty($loop_parents)) {
			$array_var_name = '$this->' . $loop_parents[0]["value"];
			$array_var_value = $args[$loop_parents[0]["value"]];
			for($i = 1; $i < count($loop_parents) - 1; $i++) {
				$array_var_name .= '["' . $loop_parents[$i]["value"] . '"]';
				$array_var_value = $array_var_value[$loop_parents[$i]["value"]];
			}

			if(isset($array_var_value[end($loop_parents)["value"]])) {
				$parent_value = $array_var_name .
				                '["' .
				                end($loop_parents)["value"] .
				                '"]';
			} elseif(isset($args[end($loop_parents)["value"]])) {
				$parent_value = '$this->' . end($loop_parents)["value"];
			} else {
				// UNDEFINED ARGUMENT ERROR
				echo "ERROR";

				return null;
			}

			$code .= "function " .
			         end($loop_parents)["value"] .
			         $iteration_number .
			         '() {$buffer="";';
			// I could have used a foreach here....
			$code .= 'for($i=0; $i < count(' . $parent_value . '); $i++) {';
		}

		for($i = count($AST) - 1; $i >= 0; $i--) {
			$iteration_number++;
			if(!isset($AST[$i]["type"]) || !isset($AST[$i]["value"])) {
				$e = new Exception(ERROR_INVALID_TOKENIZED_TEMPLATE);
				$this->exceptions[] = $e;
				$this->messages[] = array("level" => MESSAGE_LEVEL_ERROR,
				                          "http_status_code" => HTTP_INTERNAL_SERVER_ERROR,
				                          "text" => $e->getMessage());

				return null;
			}
			$value = $AST[$i]["value"];
			if(in_array($AST[$i]["type"],
				array(self::TOKEN_TYPE_LOOP_START,
				      self::TOKEN_TYPE_INVERTED_LOOP_START,
				      self::TOKEN_TYPE_VAR,
				      self::TOKEN_TYPE_UNESCAPED_VAR))) {
				if(empty($loop_parents)) {
					if(!isset($args[$value])) {
						// UNDEFINED ARG
						echo "ERROR";

						return null;
					}
					$value = '$this->' . $value;
				} else {
					if($value == ".") {
						$value = $parent_value . '[$i]';
					} else {
						if(isset($array_var_value[0][$value])) {
							$value = $array_var_name . '[$i]["' . $value . '"]';
						} elseif(isset($args[$value])) {
							// check if it is an array???????
							// check if last parent
							$value = '$this->' . $value . ''; /// $i ?????

						} else {
							echo "ERROR";

							return null;
						}
					}
				}
			}
			switch($AST[$i]["type"]) {
				default:
				case self::TOKEN_TYPE_TEXT: // var_export?
					$code .= '$buffer .= "' . $value . '";';
					break;
				case self::TOKEN_TYPE_VAR:
					$code .= '$buffer .= htmlspecialchars(' . $value . ");";
					break;
				case self::TOKEN_TYPE_UNESCAPED_VAR:
					$code .= '$buffer .= ' . $value . ";";
					break;
				case self::TOKEN_TYPE_LOOP_START:
				case self::TOKEN_TYPE_INVERTED_LOOP_START:
					$function_name = $value . $iteration_number;
					$code .= '$buffer .= ' . $function_name . "();";
					$loop_parents[] = $AST[$i];
					$this->writeCode($AST[$i - 1],
						$args,
						$loop_parents,
						$functions_code,
						$iteration_number);
					array_pop($loop_parents);
					$i--;
					break;
				case self::TOKEN_TYPE_PARTIAL:
					// load partial
					break;
				case self::TOKEN_TYPE_LOOP_END:
				case self::TOKEN_TYPE_COMMENT:
				case self::TOKEN_TYPE_CHANGE_TAGS:
					break;
			}
		}
		if(!empty($loop_parents)) {
			$code .= '} return $buffer; }';
			$functions_code[] = $code;
			array_pop($loop_parents);
		} else {
			$code .= 'return $buffer;';
		}

		$arguments = array();
		foreach($args as $key => $arg) {
			$arguments[] = 'private $' .
			               $key .
			               '=' .
			               var_export($arg, true) .
			               ';';
		}

		return array("class_vars" => $arguments,
		             "render_body" => $code,
		             "functions_code" => $functions_code);
	}

	public function getTemplateClassName($template, $args) {
		return $template . md5(json_encode($args));
	}

	public function compileTemplate($template, $args = null) {
		$args = (empty($args)) ? array() : $args;
		if(!isset($this->templates[$template])) {
			if(!$this->loadTemplate($template)) {
				return null;
			}
		}
		$template_body = $this->templates[$template];
		$args = array_merge($args, $this->args);
		$tokenized = $this->tokenizeTemplate($template_body);
		if($tokenized === null) {
			return null;
		}
		$AST = $this->parseTemplate($tokenized);
		if($AST === null) {
			return null;
		}
		$code = $this->writeCode($AST, $args);
		if($code === null) {
			return null;
		}
		$class_name = $this->getTemplateClassName($template, $args);

		return '<?php class ' .
		       $class_name .
		       '{' .
		       implode("\n", $code["class_vars"]) .
		       'function renderTemplate(){$buffer="";' .
		       $code["render_body"] .
		       '}' .
		       implode("\n", $code["functions_code"]) .
		       '}';
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
	public function renderTemplate($template, $args = null) {
		$args = (empty($args)) ? array() : $args;
		$template_class = $this->compileTemplate($template, $args);
		if($template_class === null) {
			return null;
		}
		eval("?>" . $template_class);

		$template_name = $this->getTemplateClassName($template, $args);
		if(!class_exists("$template_name")) {
			echo "errror";

			return null;
		}
		$temp = new $template_name();

		return $temp->renderTemplate();
	}

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