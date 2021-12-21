<?php

/**
 * Class TemplateEngine
 */
class TemplateEngine
{
    public const PARSE_MODE_PHP = 0;
    public const PARSE_MODE_TEMPLATE = 1;
    public const PARSE_MODES
        = array(
            self::PARSE_MODE_PHP,
            self::PARSE_MODE_TEMPLATE
        );
    public const TOKEN_TYPE_TEXT = "text";
    public const TOKEN_TYPE_VAR = "var";
    public const TOKEN_TYPE_UNESCAPED_VAR = "&";
    public const TOKEN_TYPE_LOOP_START = "#";
    public const TOKEN_TYPE_LOOP_END = "/";
    public const TOKEN_TYPE_INVERTED_LOOP_START = "^";
    public const TOKEN_TYPE_PARTIAL = ">";
    public const TOKEN_TYPE_COMMENT = "!";
    public const TOKEN_TYPE_CHANGE_TAGS = "=";
    public const TOKEN_TYPE_DEREFERENCE_OPERATOR = "*";
    public const TOKEN_TYPES
        = array(
            self::TOKEN_TYPE_CHANGE_TAGS,
            self::TOKEN_TYPE_COMMENT,
            self::TOKEN_TYPE_PARTIAL,
            self::TOKEN_TYPE_INVERTED_LOOP_START,
            self::TOKEN_TYPE_LOOP_END,
            self::TOKEN_TYPE_LOOP_END,
            self::TOKEN_TYPE_LOOP_START,
            self::TOKEN_TYPE_UNESCAPED_VAR
        );
    public const TOKENS_POINTING_TO_ARGS
        = array(
            self::TOKEN_TYPE_INVERTED_LOOP_START,
            self::TOKEN_TYPE_VAR,
            self::TOKEN_TYPE_UNESCAPED_VAR
        );
    public const TEMPLATE_OPEN_TAG = "{{";
    public const TEMPLATE_CLOSE_TAG = "}}";
    public const TEMPLATE_CLASS_PREFIX = "Template";
    public const AST_TYPE = 0;
    public const AST_VALUE = 1;
    public const INDEX_RENDER_BODY = 1;
    public const INDEX_FUNCTIONS_CODE = 2;
    /**
     * @var array<int, array<int, mixed>> $messages Messages array.
     */
    public array $messages = array();
    /**
     * @var array<int, object> $exceptions Exceptions objects array.
     */
    public array $exceptions = array();
    /**
     * @var array<string, mixed> $args Arguments array.
     */
    private array $args = array();
    /**
     * @var int $parse_mode Template parse mode.
     */
    private int $parse_mode = self::PARSE_MODE_PHP;
    /**
     * @var array<string, array<int, string>> $templates_context Template
     *                                                           context.
     */
    private array $templates_context = array();
    /**
     * @var array<string, string> $known_templates Already built templates list;
     *                                             template name => class name
     */
    private array $known_templates = array();
    /**
     * @var int $loop_counter Loop counter, used for dot-only vars in loops.
     */
    private int $loop_counter = 0;

    /**
     * Get Configuration Methods.
     * Returns the methods that will be called by the injector.
     * @return string[]
     */
    public function getConfigurationMethods()
    : array
    {
        return array("getParseMode");
    }

    /**
     * Push Context Value.
     * Pushes a value into a template's context stack.
     *
     * @param string $class_name
     * @param string $value
     *
     * @return void
     */
    public function pushContextValue(string $class_name, string $value)
    {
        $this->templates_context[$class_name][] = $value;
    }

    /**
     * Pop Context Value.
     * Pops a value from a template's context stack.
     *
     * @param string $class_name
     *
     * @return void
     */
    public function popContextValue(string $class_name)
    {
        array_pop($this->templates_context[$class_name]);
    }

    /**
     * Add Global Args.
     * Adds global args that will overwrite the template arguments.
     *
     * @param array<string,mixed> $args Arguments.
     *
     * @return void
     */
    public function addGlobalArgs(array $args)
    {
        $this->args = array_merge($this->args, $args);
    }

    /**
     * Get Parse Mode.
     * Returns the parse mode.
     * @return int
     */
    public function getParseMode()
    : int
    {
        return $this->parse_mode;
    }

    /**
     * Set Parse Mode.
     * Sets the parse mode.
     *
     * @param int $parse_mode Parse mode.
     *
     * @return void
     */
    public function setParseMode(int $parse_mode)
    {
        if (!in_array($parse_mode, self::PARSE_MODES)) {
            $e = new Exception(ERROR_INVALID_PARSE_MODE);
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => $e->getMessage()
            );

            return;
        }
        $this->parse_mode = $parse_mode;
    }

    /**
     * Write Code.
     * Writes the code for a given template.
     *
     * @param array<int, mixed>              $AST              Abstract syntax
     *                                                         tree.
     * @param array<int, array<int, string>> $loop_parents     Token parents.
     * @param array<int, string>             $functions_code   Functions code.
     * @param int                            $iteration_number Iteration number.
     *
     * @return array<int, array<int, string>>|null
     */
    private function writeCode(
        array $AST,
        array $loop_parents = array(),
        array &$functions_code = array(),
        int $iteration_number = 0
    )
    : ?array {
        $code = '';
        if (!empty($loop_parents)) {
            $end_parent = end($loop_parents);
            $code .= "function " .
                     ltrim($end_parent[self::AST_VALUE], "*") .
                     $iteration_number .
                     '($args){$buffer="";$class_name=get_class($this);';
            switch ($end_parent[self::AST_TYPE]) {
                default:
                case self::TOKEN_TYPE_LOOP_START:
                    $code .= '$count=count($this->TemplateEngine->resolveValue($class_name,"' .
                             $end_parent[self::AST_VALUE] .
                             '",$args));for($i=0;$i<$count;$i++){';
                    break;
                case self::TOKEN_TYPE_INVERTED_LOOP_START:
                    $code .= 'if(!is_array($this->TemplateEngine->resolveValue($class_name,"' .
                             $end_parent[self::AST_VALUE] .
                             '",$args))||empty($this->TemplateEngine->resolveValue($class_name,"' .
                             $end_parent[self::AST_VALUE] .
                             '",$args)){';
                    break;
            }
        } else {
            $code .= '$class_name=get_class($this);';
        }

        for ($i = count($AST) - 1; $i >= 0; $i--) {
            $iteration_number++;
            if (!is_array($AST[$i])) {
                $e = new Exception(
                    ERROR_TEMPLATE_CLASS_CREATION
                );
                $this->exceptions[] = $e;
                $this->messages[] = array(
                    MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                    MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                    MESSAGE_TEXT => $e->getMessage()
                );

                return null;
            }
            $value = (in_array(
                $AST[$i][self::AST_TYPE],
                self::TOKENS_POINTING_TO_ARGS
            )) ?
                '$this->TemplateEngine->resolveValue($class_name,"' .
                $AST[$i][self::AST_VALUE] .
                '",$args)' : $AST[$i][self::AST_VALUE];

            switch ($AST[$i][self::AST_TYPE]) {
                default:
                case self::TOKEN_TYPE_TEXT:
                    $code .= '$buffer.="' . $value . '";';
                    break;
                case self::TOKEN_TYPE_VAR:
                    $code .= '$buffer.=htmlspecialchars(' . $value . ");";
                    break;
                case self::TOKEN_TYPE_UNESCAPED_VAR:
                    $code .= '$buffer.=' . $value . ";";
                    break;
                case self::TOKEN_TYPE_LOOP_START:
                case self::TOKEN_TYPE_INVERTED_LOOP_START:
                    $function_name = $value . $iteration_number;
                    $code .= '$this->TemplateEngine->pushContextValue(get_class($this),"' .
                             $value .
                             '");';
                    $code .= '$buffer.=$this->' . $function_name . '($args);';
                    $code .= '$this->TemplateEngine->popContextValue(get_class($this));';
                    $loop_parents[] = $AST[$i];
                    if (!is_array($AST[$i - 1])) {
                        $e = new Exception(
                            ERROR_TEMPLATE_AST_INCONSISTENCY
                        );
                        $this->exceptions[] = $e;
                        $this->messages[] = array(
                            MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                            MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                            MESSAGE_TEXT => $e->getMessage()
                        );

                        return null;
                    }
                    $this->writeCode(
                        $AST[$i - 1],
                        $loop_parents,
                        $functions_code,
                        $iteration_number
                    );
                    array_pop($loop_parents);
                    $i--;
                    break;
                case self::TOKEN_TYPE_PARTIAL:
                    $class_name = ltrim($value, "*") . $iteration_number;
                    $iteration_number++;
                    $code .= '$' .
                             $class_name .
                             '=$this->TemplateEngine->loadTemplate($this->TemplateEngine->resolveValue($class_name,"' .
                             $value .
                             '"));if($' .
                             $class_name .
                             '!==null){$buffer.=$' .
                             $class_name .
                             '->render($args);}';
                    break;
                case self::TOKEN_TYPE_LOOP_END:
                case self::TOKEN_TYPE_COMMENT:
                case self::TOKEN_TYPE_CHANGE_TAGS:
                    break;
            }
        }
        if (!empty($loop_parents)) {
            $code .= '} return $buffer;}';
            $functions_code[] = $code;
            array_pop($loop_parents);
        } else {
            $code .= 'return $buffer;';
        }

        return array(
            self::INDEX_RENDER_BODY => array($code),
            self::INDEX_FUNCTIONS_CODE => $functions_code
        );
    }

    /**
     * Resolve Value.
     * Resolves a value in the template class context.
     *
     * @param string               $class_name Class name.
     * @param string               $value      Value to resolve.
     * @param array<string, mixed> $args       Arguments.
     *
     * @return mixed
     */
    public function resolveValue(
        string $class_name,
        string $value,
        array $args,
    )
    : mixed {
        if ($value !== ".") {
            $this->loop_counter = 0;
        }
        $parents = $this->templates_context[$class_name];
        $parents[] = $value;
        $result = $this->resolveValueHelper(
            $args,
            $parents
        );
        if ($result === null) {
            $result = $this->resolveValueHelper(
                $args,
                array($value)
            );
        }
        if ($result === null) {
            $e = new Exception(ERROR_UNDEFINED_ARGUMENT);
            $this->exceptions[] = $e;
            $this->messages[]
                = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_WARNING,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => $e->getMessage()
            );
        }

        return $result;
    }

    /**
     * Resolve Value Helper.
     * Helps to resolve a value in the template class context.
     *
     * @param array<string, mixed> $args    Arguments.
     * @param array<int, string>   $parents Parents.
     *
     * @return mixed
     */
    private function resolveValueHelper(
        array $args,
        array $parents
    )
    : mixed {
        $first_index = explode(
                           ".",
                           ltrim(
                               $parents[0],
                               self::TOKEN_TYPE_DEREFERENCE_OPERATOR
                           )
                       )[0];
        $loop_parent = array($first_index => $args[$first_index]);
        for ($i = 0; $i < count($parents); $i++) {
            $parent_raw_value = $parents[$i];

            $dereference_levels = 0;
            for ($j = 0; $j < strlen($parent_raw_value); $j++) {
                if ($parent_raw_value[$j] === "*") {
                    $dereference_levels++;
                } else {
                    break;
                }
            }
            $parent_value = substr($parent_raw_value, $dereference_levels);
            if ($parent_value === ".") {
                $loop_parent = $loop_parent[$this->loop_counter];
                $this->loop_counter++;
            }
            foreach (explode(".", $parent_value) as $value) {
                if (is_array($loop_parent) && isset($loop_parent[$value])) {
                    $loop_parent = $loop_parent[$value];
                } elseif (is_object($loop_parent) && method_exists(
                        $loop_parent,
                        $value
                    )) {
                    $loop_parent = $loop_parent->{$value}();
                } elseif (is_object($loop_parent) && property_exists(
                        $loop_parent,
                        $value
                    )) {
                    $loop_parent = $loop_parent->{$value};
                } else {
                    continue;
                }
            }
            for ($j = 0; $j < $dereference_levels; $j++) {
                if (isset($args[$loop_parent])) {
                    $loop_parent = $args[$loop_parent];
                } else {
                    $e = new Exception(ERROR_INVALID_DEREFERENCE);
                    $this->exceptions[] = $e;
                    $this->messages[]
                        = array(
                        MESSAGE_LEVEL => MESSAGE_LEVEL_WARNING,
                        MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                        MESSAGE_TEXT => $e->getMessage()
                    );
                    continue;
                }
            }
        }

        return $loop_parent;
    }

    /**
     * Load Template.
     * Loads a template file into the $templates array.
     *
     * @param string $template Template name.
     *
     * @return object|null
     */
    public function loadTemplate(string $template)
    : ?object {
        if (array_key_exists($template, $this->known_templates)) {
            return $this->getTemplateClass($this->known_templates[$template]);
        }
        $template_file = TEMPLATE_DIR . $template . ".php";
        if (!file_exists($template_file) ||
            ($content = file_get_contents($template_file)) === false) {
            $e = new Exception(ERROR_TEMPLATE_FILE_NOT_FOUND);
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => $e->getMessage()
            );

            return null;
        }

        $class_name = $this->getTemplateClassName($template, $content);

        $tokenized = $this->tokenizeTemplate($content);
        if ($tokenized === null) {
            return null;
        }
        $AST = $this->parseTemplate($tokenized);
        if ($AST === null) {
            return null;
        }
        $code = $this->compileTemplate($class_name, $AST);
        if ($code === null) {
            return null;
        }
        $this->evalTemplate($code);
        if (!class_exists($class_name)) {
            return null;
        }
        $this->known_templates[$template] = $class_name;
        $this->templates_context[$class_name] = array();

        return $this->getTemplateClass($class_name);
    }

    /**
     * Get Template Class.
     * Initializes a template class instance and returns it.
     *
     * @param string $class_name
     *
     * @return object
     */
    public function getTemplateClass(string $class_name)
    : object {
        return new $class_name($this);
    }

    /**
     * Compile Template.
     * Compiles and evaluates a template.
     *
     * @param string            $class_name Template class name.
     * @param array<int, mixed> $AST        Abstract syntax tree.
     *
     * @return string|null
     */
    private function compileTemplate(
        string $class_name,
        array $AST
    )
    : ?string {
        $code = $this->writeCode($AST);
        if ($code === null) {
            return null;
        }

        return "<?php class " .
               $class_name .
               '{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}' .
               'function render($args){$buffer="";' .
               $code[self::INDEX_RENDER_BODY][0] .
               "}" .
               implode("", $code[self::INDEX_FUNCTIONS_CODE]) .
               "}";
    }

    /**
     * Tokenize Template.
     * Tokenizes a template.
     *
     * @param string $template_body Template body.
     *
     * @return array<int, array<int, string>>|null
     */
    private function tokenizeTemplate(string $template_body)
    : ?array {
        $tokenized = array();
        $open_tag = self::TEMPLATE_OPEN_TAG;
        $close_tag = self::TEMPLATE_CLOSE_TAG;
        $buffer = "";
        $type = self::TOKEN_TYPE_TEXT;
        $template_length = strlen($template_body);
        $unclosed_token = false;
        for ($i = 1; $i < $template_length; $i++) {
            $i--;
            $close_tag_length = strlen($close_tag);
            if (substr($template_body, $i, $close_tag_length) == $close_tag) {
                if ($type == self::TOKEN_TYPE_CHANGE_TAGS) {
                    $tags = explode(" ", $buffer);
                    if (count($tags) != 2) {
                        $e = new Exception(
                            ERROR_MALFORMED_TAG_CHANGE
                        );
                        $this->exceptions[] = $e;
                        $this->messages[]
                            = array(
                            MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                            MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                            MESSAGE_TEXT => $e->getMessage()
                        );

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
            if (substr($template_body, $i, $open_tag_length) == $open_tag) {
                if ($unclosed_token) {
                    $e = new Exception(ERROR_UNCLOSED_TOKEN);
                    $this->exceptions[] = $e;
                    $this->messages[]
                        = array(
                        MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                        MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                        MESSAGE_TEXT => $e->getMessage()
                    );
                }
                $unclosed_token = true;
                if (in_array(
                    $template_body[$i + $open_tag_length],
                    self::TOKEN_TYPES
                )) {
                    $type = $template_body[$i + $open_tag_length];
                    $i += 1;
                } else {
                    $type = self::TOKEN_TYPE_VAR;
                }
                $i += $open_tag_length;
            }
            $buffer = "";
            while (!(substr(
                         $template_body,
                         $i,
                         $close_tag_length
                     ) == $close_tag) &&
                   !(substr($template_body, $i, $open_tag_length) ==
                     $open_tag) &&
                   $i < $template_length) {
                $buffer .= $template_body[$i];
                $i++;
            }
            if ($unclosed_token) {
                $buffer = trim($buffer);
                if ($type == self::TOKEN_TYPE_CHANGE_TAGS) {
                    if (substr(
                            $buffer,
                            -1
                        ) != self::TOKEN_TYPE_CHANGE_TAGS) {
                        $e = new Exception(
                            ERROR_MALFORMED_TAG_CHANGE
                        );
                        $this->exceptions[] = $e;
                        $this->messages[]
                            = array(
                            MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                            MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                            MESSAGE_TEXT => $e->getMessage()
                        );

                        return null;
                    }
                    $buffer = rtrim(
                        $buffer,
                        self::TOKEN_TYPE_CHANGE_TAGS
                    );
                }
            }
            if (empty($buffer)) {
                continue;
            }
            $tokenized[] = array(
                self::AST_TYPE => $type,
                self::AST_VALUE => $buffer
            );
        }
        if ($unclosed_token) {
            $e = new Exception(ERROR_UNCLOSED_TOKEN);
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => $e->getMessage()
            );
        }

        return $tokenized;
    }

    /**
     * Parse Template.
     * Generates an abstract syntax tree from a tokenized template.
     *
     * @param array<int, array<int, string>> $tokenized Tokenized template.
     *
     * @return array<int, mixed>|null
     */
    private function parseTemplate(array $tokenized)
    : ?array {
        $AST = array();
        $branches = array();
        $branch_names = array();
        for ($i = count($tokenized) - 1; $i >= 0; $i--) {
            $type = $tokenized[$i][self::AST_TYPE];
            $value = $tokenized[$i][self::AST_VALUE];

            if ($type == self::TOKEN_TYPE_LOOP_END) {
                $index = count($AST);
                $AST[$index] = array();
                $branches[] = &$AST;
                $AST = &$AST[$index];
                $branch_names[] = $value;
            }
            if ($type == self::TOKEN_TYPE_LOOP_START ||
                $type == self::TOKEN_TYPE_INVERTED_LOOP_START) {
                if ($value != end($branch_names)) {
                    $e = new Exception(ERROR_LOOP_TOKEN_MISMATCH);
                    $this->exceptions[] = $e;
                    $this->messages[]
                        = array(
                        MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                        MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                        MESSAGE_TEXT => $e->getMessage()
                    );

                    return null;
                }
                $index = count($branches) - 1;
                if (!empty($branches[$index])) {
                    $AST = &$branches[$index];
                } else {
                    $AST = array($AST);
                }

                array_pop($branches);
                array_pop($branch_names);
            }
            $AST[] = $tokenized[$i];
        }
        if (!empty($branch_names)) {
            $e = new Exception(ERROR_UNCLOSED_LOOP_TOKEN);
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => $e->getMessage()
            );

            return null;
        }

        return $AST;
    }

    /**
     * Get Template Class Name.
     * Returns the template class name.
     *
     * @param string $template_name    Template class name.
     * @param string $template_content Template file raw content.
     *
     * @return string
     */
    public function getTemplateClassName(
        string $template_name,
        string $template_content
    )
    : string {
        return self::TEMPLATE_CLASS_PREFIX .
               ltrim($template_name, "*") .
               md5($template_content);
    }

    /**
     * Evaluate Template.
     * Evaluates a template code: loads its class into the memory.
     *
     * @param string $code Code.
     *
     * @return bool
     */
    private function evalTemplate(string $code)
    : bool {
        try {
            echo $code;
            eval("?>" . $code);

            return true;
        } catch (Throwable $e) {
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => $e->getMessage()
            );

            return false;
        }
    }
}
