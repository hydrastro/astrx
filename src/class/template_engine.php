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
            self::TOKEN_TYPE_LOOP_START,
            self::TOKEN_TYPE_INVERTED_LOOP_START,
            self::TOKEN_TYPE_VAR,
            self::TOKEN_TYPE_UNESCAPED_VAR
        );
    public const TEMPLATE_OPEN_TAG = "{{";
    public const TEMPLATE_CLOSE_TAG = "}}";
    public const AST_TYPE = 0;
    public const AST_VALUE = 1;
    public const INDEX_CLASS_VARS = 0;
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
     * @var array<string, string> $templates Templates array.
     */
    private array $templates = array();
    /**
     * @var array<string, mixed> $args Arguments array.
     */
    private array $args = array();
    /**
     * @var int $parse_mode Template parse mode.
     */
    private int $parse_mode = self::PARSE_MODE_PHP;

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
     * Render.
     * Renders a template: either a PHP template or a template that needs to be
     * compiled.
     *
     * @param string              $template   Template name.
     * @param array<string,mixed> $args       Arguments.
     * @param int                 $parse_mode Parse mode.
     *
     * @return string|null
     */
    public function render(
        string $template,
        array $args = array(),
        int $parse_mode
        = self::PARSE_MODE_PHP
    )
    : ?string {
        if (!empty($parse_mode)) {
            if (!in_array($parse_mode, self::PARSE_MODES)) {
                $e = new Exception(ERROR_INVALID_PARSE_MODE);
                $this->exceptions[] = $e;
                $this->messages[] = array(
                    MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                    MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                    MESSAGE_TEXT => $e->getMessage()
                );
            }
        } else {
            $parse_mode = $this->getParseMode();
        }
        switch ($parse_mode) {
            default:
            case self::PARSE_MODE_PHP:
                return $this->renderPHP($template, $args);
            case self::PARSE_MODE_TEMPLATE:
                return $this->renderTemplate($template, $args);
        }
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
     * Render PHP.
     * Renders a PHP template with output buffering functions.
     *
     * @param string              $template
     * @param array<string,mixed> $args
     *
     * @return string|null
     */
    private function renderPHP(string $template, array $args = array())
    : ?string {
        extract($args);
        ob_start();
        require(TEMPLATE_DIR . "$template.php");
        $content = ob_get_clean();

        return !empty($content) ? $content : null;
    }

    /**
     * Render Template.
     * Compiles and gets a processed template.
     *
     * @param string               $template Template name.
     * @param array<string, mixed> $args     Arguments.
     *
     * @return string|null
     */
    private function renderTemplate(string $template, array $args = array())
    : ?string {
        $template_class = $this->compileTemplate($template, $args);
        if ($template_class === null || !$this->evalTemplate($template_class)) {
            $e = new Exception(ERROR_TEMPLATE_CLASS_CREATION);
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => $e->getMessage()
            );

            return null;
        }

        $template_name = $this->getTemplateClassName($template, $args);
        if (!class_exists("$template_name")) {
            $e = new Exception(ERROR_TEMPLATE_CLASS_CREATION);
            $this->exceptions[] = $e;
            $this->messages[] = array(
                MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                MESSAGE_TEXT => $e->getMessage()
            );

            return null;
        }
        $objects = array();
        foreach ($args as $arg) {
            if (is_object($arg)) {
                $objects[] = $arg;
            }
        }
        $temp = new $template_name(...$objects);

        /**
         * @phpstan-ignore-next-line
         */
        return $temp->renderTemplate();
    }

    /**
     * Remove Template.
     * Removes a loaded template.
     *
     * @param string $template Template name.
     *
     * @return bool
     */
    public function removeTemplate(string $template)
    : bool {
        if (isset($this->templates[$template])) {
            unset($this->templates[$template]);

            return true;
        }
        $e = new Exception(ERROR_TEMPLATE_NOT_LOADED);
        $this->exceptions[] = $e;
        $this->messages[] = array(
            MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
            MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
            MESSAGE_TEXT => $e->getMessage()
        );

        return false;
    }

    /**
     * Removes all loaded templates.
     * @return void
     */
    public function removeAll()
    {
        $this->templates = array();
    }

    /**
     * Assemble Code.
     * Assembles the template class code.
     *
     * @param string               $class_name Class name.
     * @param array<int, mixed>    $AST        Abstract syntax tree.
     * @param array<string, mixed> $args       Arguments.
     *
     * @return string|null
     */
    private function assembleCode(string $class_name, array $AST, array $args)
    : ?string {
        $code = $this->writeCode($AST, $args);
        if ($code === null) {
            return null;
        }

        return "<?php class " .
               $class_name .
               "{" .
               implode("\n", $code[self::INDEX_CLASS_VARS]) .
               'function renderTemplate(){$buffer="";' .
               $code[self::INDEX_RENDER_BODY][0] .
               "}" .
               implode("\n", $code[self::INDEX_FUNCTIONS_CODE]) .
               "}";
    }

    /**
     * Write Code.
     * Writes the code for a given template.
     *
     * @param array<int, mixed>              $AST              Abstract syntax
     *                                                         tree.
     * @param array<string, mixed>           $args             Arguments.
     * @param array<int, array<int, string>> $loop_parents     Token parents.
     * @param array<int, string>             $functions_code   Functions code.
     * @param int                            $iteration_number Iteration number.
     *
     * @return array<int, array<int, string>>|null
     */
    private function writeCode(
        array $AST,
        array $args,
        array $loop_parents = array(),
        array &$functions_code = array(),
        int $iteration_number = 0
    )
    : ?array
    {
        if (empty($functions_code)) {
            $constructor_args = "";
            $constructor_body = "";
            foreach ($args as $key => $arg) {
                if (is_object($arg)) {
                    $constructor_args .= '$' . $key . ",";
                    $constructor_body .= '$this->' . $key . '=$' . $key . ';';
                }
            }
            $constructor_args = rtrim($constructor_args, ",");
            $functions_code[] = "public function __construct(" .
                                rtrim($constructor_args, ",") .
                                "){" .
                                $constructor_body .
                                "}";
        }

        $code = "";
        if (!empty($loop_parents)) {
            $resolved_parent = $this->resolveValue($args, $loop_parents);
            $end_parent = end($loop_parents);
            $code .= "function " .
                     ltrim($end_parent[self::AST_VALUE], "*") .
                     $iteration_number .
                     '() {$buffer="";';
            switch ($end_parent[self::AST_TYPE]) {
                default:
                case self::TOKEN_TYPE_LOOP_START:
                    $code .= 'for($i=0; $i < count(' .
                             $resolved_parent .
                             '); $i++) {';
                    break;
                case self::TOKEN_TYPE_INVERTED_LOOP_START:
                    $code .= "if(empty(" . $resolved_parent . ") {";
                    break;
            }
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
            )) ? $this->resolveValue($args, $loop_parents, $AST[$i]) :
                $AST[$i][self::AST_VALUE];

            switch ($AST[$i][self::AST_TYPE]) {
                default:
                case self::TOKEN_TYPE_TEXT:
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
                $code .= '$buffer .= ' .
                         $function_name .
                         "($constructor_args);";

                $loop_parents[] = $AST[$i];
                if (!is_array($AST[$i - 1])) {
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
                    $this->writeCode(
                        $AST[$i - 1],
                        $args,
                        $loop_parents,
                        $functions_code,
                        $iteration_number
                    );
                    array_pop($loop_parents);
                    $i--;
                    break;
                case self::TOKEN_TYPE_PARTIAL:
                    $class_name = $this->resolveValue(
                        $args,
                        $loop_parents,
                        $AST[$i],
                        true
                    );
                    if (!is_string($class_name)) {
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
                    $this->loadTemplate($class_name);
                    $partial_code = $this->compileTemplate($class_name, $args);
                    $partials_code[] = $partial_code;
                    if ($partial_code === null ||
                        !$this->evalTemplate($partial_code)) {
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
                    $class_name = $this->getTemplateClassName(
                        $class_name,
                        $args
                    );
                    $code .= "$" .
                             $class_name .
                             " = new " .
                             $class_name .
                             '();$buffer.=$' .
                             $class_name .
                             "->renderTemplate();";
                    break;
                case self::TOKEN_TYPE_LOOP_END:
                case self::TOKEN_TYPE_COMMENT:
                case self::TOKEN_TYPE_CHANGE_TAGS:
                    break;
            }
        }
        if (!empty($loop_parents)) {
            $code .= '} return $buffer; }';
            $functions_code[] = $code;
            array_pop($loop_parents);
        } else {
            $code .= 'return $buffer;';
        }

        $arguments = array();
        foreach ($args as $key => $arg) {
            if (is_object($arg)) {
                $arguments[] = "private $" . $key . ";";
                continue;
            }
            $arguments[] = "private $" .
                           $key .
                           "=" .
                           var_export($arg, true) .
                           ";";
        }

        return array(
            self::INDEX_CLASS_VARS => $arguments,
            self::INDEX_RENDER_BODY => array($code),
            self::INDEX_FUNCTIONS_CODE => $functions_code
        );
    }

    /**
     * Resolve Value.
     * Resolves a value in the template class context.
     *
     * @param array<string, mixed>           $args      Arguments.
     * @param array<int, array<int, string>> $parents   Token parents.
     * @param array<int, string>             $token     Current token.
     * @param bool                           $get_value Return actual value.
     *
     * @return mixed
     */
    private function resolveValue(
        array $args,
        array $parents,
        array $token = array(),
        bool $get_value
        = false
    )
    : mixed {
        if (empty($token) && !empty($parents)) {
            $token = end($parents);
        } else {
            $parents[] = $token;
        }

        $result = $this->resolveValueHelper(
            $args,
            $parents,
            $get_value
        );
        if ($result === null) {
            $result = $this->resolveValueHelper(
                $args,
                array($token),
                $get_value
            );
        }

        return $result;
    }

    /**
     * Resolve Value Helper.
     * Helps to resolve a value in the template class context.
     *
     * @param array<string, mixed>           $args      Arguments.
     * @param array<int, array<int, string>> $parents   Token parents.
     * @param bool                           $get_value Return actual value.
     *
     * @return mixed
     */
    private function resolveValueHelper(
        array $args,
        array $parents,
        bool $get_value
        = false
    )
    : mixed {
        if (empty($parents)) {
            return null;
        }
        $result = '$this->';
        $first_index = explode(
                           ".",
                           ltrim($parents[0][self::AST_VALUE], "*")
                       )[0];
        $loop_parent = array($first_index => $args[$first_index]);
        $first = true;
        for ($i = 0; $i < count($parents); $i++) {
            $parent_raw_value = $parents[$i][self::AST_VALUE];
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
                $result .= '[$i]';
                continue;
            }
            foreach (explode(".", $parent_value) as $value) {
                if (is_array($loop_parent) && isset($loop_parent[$value])) {
                    $loop_parent = $loop_parent[$value];
                    if ($first) {
                        $result .= $value;
                        $first = false;
                    } else {
                        $result .= '["' . $value . '"]';
                    }
                } elseif (method_exists(
                    $loop_parent,
                    $value
                )) {
                    $loop_parent = $loop_parent->{$value}();
                    if ($first) {
                        $result .= $value;
                        $first = false;
                    } else {
                        $result .= "->" . $value . "()";
                    }
                } elseif (property_exists(
                    $loop_parent,
                    $value
                )) {
                    $loop_parent = $loop_parent->{$value};
                    if ($first) {
                        $result .= $value;
                        $first = false;
                    } else {
                        $result .= "->" . $value;
                    }
                } else {
                    $e = new Exception(ERROR_UNDEFINED_ARGUMENT);
                    $this->exceptions[] = $e;
                    $this->messages[]
                        = array(
                        MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                        MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                        MESSAGE_TEXT => $e->getMessage()
                    );

                    return null;
                }
            }
            for ($j = 0; $j < $dereference_levels; $j++) {
                if ($j === 0) {
                    $result = '$this->{' . $result . "}";
                    continue;
                }
                if (isset($args[$loop_parent])) {
                    $loop_parent = $args[$loop_parent];
                    $result = '$this->{' . $result . "}";
                } else {
                    $e = new Exception(ERROR_INVALID_DEREFERENCE);
                    $this->exceptions[] = $e;
                    $this->messages[]
                        = array(
                        MESSAGE_LEVEL => MESSAGE_LEVEL_ERROR,
                        MESSAGE_HTTP_STATUS => HTTP_INTERNAL_SERVER_ERROR,
                        MESSAGE_TEXT => $e->getMessage()
                    );

                    return null;
                }
            }
        }

        return ($get_value) ? $loop_parent : $result;
    }

    /**
     * Load Template.
     * Loads a template file into the $templates array.
     *
     * @param string $template Template name.
     *
     * @return bool
     */
    public function loadTemplate(string $template)
    : bool {
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

            return false;
        }
        $this->templates[$template]
            = $content;

        return true;
    }

    /**
     * Compile Template.
     * Compiles and evaluates a template.
     *
     * @param string              $template Template name.
     * @param array<string,mixed> $args     Arguments.
     *
     * @return string|null
     */
    private function compileTemplate(
        string $template,
        array $args = array(),
    )
    : ?string {
        $args = (empty($args)) ? array() : $args;
        if (!isset($this->templates[$template])) {
            if (!$this->loadTemplate($template)) {
                return null;
            }
        }
        $template_body = $this->templates[$template];
        $args = array_merge($args, $this->args);
        $tokenized = $this->tokenizeTemplate($template_body);
        if ($tokenized === null) {
            return null;
        }
        $AST = $this->parseTemplate($tokenized);
        if ($AST === null) {
            return null;
        }
        $class_name = $this->getTemplateClassName($template, $args);

        return $this->assembleCode($class_name, $AST, $args);
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
     * @param string               $template Template class name.
     * @param array<string, mixed> $args     Arguments.
     *
     * @return string
     */
    public function getTemplateClassName(string $template, array $args)
    : string {
        $json = json_encode($args);
        $json = ($json) ?: "";

        return ltrim($template, "*") . md5($json);
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
