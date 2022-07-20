<?php
/** @noinspection PhpUnused */

declare(strict_types = 1);
/**
 * Class TemplateEngine.
 */
class TemplateEngine
{
    public const PARSE_MODE_PLAIN = 0;
    public const PARSE_MODE_TEMPLATE = 1;
    public const PARSE_MODES
        = array(
            self::PARSE_MODE_PLAIN,
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
    public const TOKEN_OPERATOR_DEREFERENCE = "*";
    public const TOKEN_OPERATOR_DOT = ".";
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
    public const ERROR_INVALID_PARSE_MODE = 0;
    public const ERROR_TEMPLATE_CLASS_CREATION = 1;
    public const ERROR_TEMPLATE_AST_INCONSISTENCY = 2;
    public const ERROR_UNDEFINED_TOKEN_ARGUMENT = 3;
    public const ERROR_UNDEFINED_TOKEN_ARGUMENT_2 = 4;
    public const ERROR_INVALID_DEREFERENCE = 5;
    public const ERROR_TEMPLATE_FILE_NOT_FOUND = 6;
    public const ERROR_MALFORMED_TAG_CHANGE = 7;
    public const ERROR_UNCLOSED_TOKEN = 8;
    public const ERROR_MALFORMED_TAG_CHANGE_2 = 9;
    public const ERROR_UNCLOSED_TOKEN_2 = 10;
    public const ERROR_LOOP_TOKEN_MISMATCH = 11;
    public const ERROR_UNCLOSED_LOOP_TOKEN = 12;
    public const ERROR_TEMPLATE_EVALUATION = 13;
    /**
     * @var array<int, array<int, mixed>> $results Results array.
     */
    public array $results = array();
    /**
     * @var array<string, mixed> $args Arguments array.
     */
    private array $args = array();
    /**
     * @var int $parse_mode Template parse mode.
     */
    private int $parse_mode = self::PARSE_MODE_TEMPLATE;
    /**
     * @var array<string, string> $known_templates Already built templates list;
     *                                             template name => class name
     */
    private array $known_templates = array();
    /**
     * @var string $template_dir Template directory.
     */
    private string $template_dir
        = ".." .
          DIRECTORY_SEPARATOR .
          "src" .
          DIRECTORY_SEPARATOR .
          "template" .
          DIRECTORY_SEPARATOR;

    /**
     * Get Configuration Methods.
     * Returns the methods that will be called by the injector.
     * @return string[]
     */
    public function getConfigurationMethods()
    : array
    {
        return array("setTemplateDir");
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
    : void {
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
     * @return bool
     */
    public function setParseMode(int $parse_mode)
    : bool {
        if (!in_array($parse_mode, self::PARSE_MODES)) {
            $this->results[] = array(
                self::ERROR_INVALID_PARSE_MODE
            );

            return false;
        }
        $this->parse_mode = $parse_mode;

        return true;
    }

    /**
     * Resolve Value.
     * Resolves a value in the template class context.
     *
     * @param string               $value      Value to resolve.
     * @param array<string, mixed> $args       Arguments.
     * @param mixed                $parent     Parent value.
     * @param int                  $loop_index Loop index.
     *
     * @return mixed
     */
    public function resolveValue(
        string $value,
        array $args,
        mixed $parent = null,
        int $loop_index = 0
    )
    : mixed {
        if ($value === self::TOKEN_OPERATOR_DOT) {
            if (is_array($parent) && isset($parent[$loop_index])) {
                return $parent[$loop_index];
            } else {
                $this->results[] = array(
                    self::ERROR_UNDEFINED_TOKEN_ARGUMENT,
                    array(
                        "parent" => print_r($parent, true),
                        "args" => print_r($args, true)
                    )
                );

                return "";
            }
        }
        $result = null;
        if (!empty($parent) && is_array($parent)) {
            if (isset($parent[0]) && is_array($parent[0])) {
                $parent = $parent[$loop_index];
            }
            $result = $this->resolveValueHelper(
                $parent,
                $value,
            );
        }
        if ($result === null) {
            $result = $this->resolveValueHelper(
                $args,
                $value,
            );
        }
        if ($result === null) {
            $this->results[] = array(
                self::ERROR_UNDEFINED_TOKEN_ARGUMENT_2,
                array(
                    "parent" => print_r($parent, true),
                    "args" => print_r($args, true)
                )
            );
        }

        return $result;
    }

    /**
     * Resolve Value Helper.
     * Helps to resolve a value in the template class context.
     *
     * @param array<string, mixed> $args  Arguments.
     * @param string               $value Value to resolve.
     *
     * @return mixed
     */
    private function resolveValueHelper(
        array $args,
        string $value,
    )
    : mixed {
        $exploded = explode(self::TOKEN_OPERATOR_DOT, $value);
        $current_value = $args;
        $dereference_levels = 0;
        for ($i = 0; $i < strlen($exploded[0]); $i++) {
            if ($exploded[0][$i] === self::TOKEN_OPERATOR_DEREFERENCE) {
                $dereference_levels++;
            } else {
                break;
            }
        }
        $clean_exploded = $exploded;
        $clean_exploded[0] = substr($exploded[0], $dereference_levels);
        for ($i = 0; $i < count($clean_exploded); $i++) {
            if (!is_array($current_value)) {
                return null;
            }
            if (!isset($current_value[$clean_exploded[$i]])) {
                return null;
            } else {
                $current_value = $current_value[$clean_exploded[$i]];
            }
        }
        for ($j = 0; $j < $dereference_levels; $j++) {
            if (is_string($current_value) && isset($args[$current_value])) {
                $current_value = $args[$current_value];
            } else {
                $this->results[] = array(
                    self::ERROR_INVALID_DEREFERENCE,
                    array("value" => $value, "args" => print_r($args, true))
                );
                // continue;
            }
        }

        return $current_value;
    }

    /**
     * Load Template.
     * Loads a template file into the $templates array.
     *
     * @param string $template       Template name.
     * @param int    $parse_mode     Parse mode.
     * @param bool   $php_processing PHP processing flag.
     *
     * @return object|null
     */
    public function loadTemplate(
        string $template,
        int $parse_mode
        = self::PARSE_MODE_TEMPLATE,
        bool $php_processing = false
    )
    : ?object {
        if (array_key_exists($template, $this->known_templates)) {
            return $this->getTemplateClass($this->known_templates[$template]);
        }

        $template_file = $this->getTemplateDir() . $template . ".php";
        if (!file_exists($template_file) ||
            ($content = file_get_contents($template_file)) === false) {
            $this->results[] = array(
                self::ERROR_TEMPLATE_FILE_NOT_FOUND,
                array(
                    "template" => $template,
                    "template_file" => $template_file
                )
            );

            return null;
        }
        $class_name = $this->getTemplateClassName($template, $content);

        if ($parse_mode == self::PARSE_MODE_TEMPLATE) {
            $tokenized = $this->tokenizeTemplate($content);
            if ($tokenized === null) {
                return null;
            }
            $AST = $this->parseTemplate($tokenized);
            if ($AST === null) {
                return null;
            }
            $code = $this->compileTemplate($class_name, $AST, $php_processing);
            if ($code === null) {
                return null;
            }
        } else {
            $code = '<?php class ' .
                    $class_name .
                    '{function render($args=array(),$parent=array()){';
            if ($php_processing) {
                $code .= 'extract($args);ob_start();
                    require("' .
                         $this->getTemplateDir() .
                         $template .
                         '.php");$buffer = ob_get_clean();';
            } else {
                $code .= '$buffer=file_get_contents("' .
                         $this->getTemplateDir() .
                         $template .
                         '.php");';
            }
            $code .= 'return ($buffer) ? $buffer : "";}}';
        }

        if (!$this->evalTemplate($code) || !class_exists($class_name)) {
            return null;
        }

        $this->known_templates[$template] = $class_name;

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
     * Get Templates Dir.
     * Returns the template directory.
     * @return string
     */
    public function getTemplateDir()
    : string
    {
        return $this->template_dir;
    }

    /**
     * Set Templates Dir.
     * Sets the template directory.
     *
     * @param string $template_dir Template directory.
     */
    public function setTemplateDir(string $template_dir)
    : void {
        $this->template_dir = $template_dir;
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
               ltrim($template_name, self::TOKEN_OPERATOR_DEREFERENCE) .
               md5($template_content);
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
                        $this->results[] = array(
                            self::ERROR_MALFORMED_TAG_CHANGE
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
                    $this->results[] = array(
                        self::ERROR_UNCLOSED_TOKEN
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
                        $this->results[] = array(
                            self::ERROR_MALFORMED_TAG_CHANGE_2
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
            $this->results[] = array(
                self::ERROR_UNCLOSED_TOKEN_2
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
                    $this->results[] = array(
                        self::ERROR_LOOP_TOKEN_MISMATCH,
                        array(
                            "opening_tag" => $value,
                            "closing_tag" => end($branch_names)
                        )
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
            $this->results[] = array(
                self::ERROR_UNCLOSED_LOOP_TOKEN,
                array("unclosed_tokens" => print_r($branch_names, true))
            );

            return null;
        }

        return $AST;
    }

    /**
     * Compile Template.
     * Compiles and evaluates a template.
     *
     * @param string            $class_name     Template class name.
     * @param array<int, mixed> $AST            Abstract syntax tree.
     * @param bool              $php_processing PHP processing flag.
     *
     * @return string|null
     */
    private function compileTemplate(
        string $class_name,
        array $AST,
        bool $php_processing
    )
    : ?string {
        $code = $this->writeCode($AST);
        if ($code === null) {
            return null;
        }

        $class = "<?php class " .
                 $class_name .
                 '{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}' .
                 'function render($args=array(),$parent=array()){$buffer="";' .
                 $code[self::INDEX_RENDER_BODY][0];
        if ($php_processing) {
            $class .= 'extract($args);ob_start();eval("?>" . $buffer);$buffer = 
                ob_get_clean();';
        }
        $class .= 'return ($buffer) ? $buffer : "";}' .
                  implode("", $code[self::INDEX_FUNCTIONS_CODE]) .
                  "}";

        return $class;
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
            $code .= "function " . ltrim(
                    $end_parent[self::AST_VALUE],
                    self::TOKEN_OPERATOR_DEREFERENCE
                ) . $iteration_number . '($args,$parent){$buffer="";$i=0;';
            $code .= match ($end_parent[self::AST_TYPE]) {
                default => '$resolved=$this->TemplateEngine->resolveValue("' .
                           $end_parent[self::AST_VALUE] .
                           '",$args,$parent,$i);$count=(is_countable($resolved))?count($resolved):0;$parent=$resolved;for($i=0;$i<$count;$i++){',
                self::TOKEN_TYPE_INVERTED_LOOP_START => 'if(!is_array($this->TemplateEngine->resolveValue("' .
                                                        $end_parent[self::AST_VALUE] .
                                                        '",$args,$i))||empty($this->TemplateEngine->resolveValue("' .
                                                        $end_parent[self::AST_VALUE] .
                                                        '",$args,$i)){',
            };
        } else {
            $code .= '$i=0;';
        }

        for ($i = count($AST) - 1; $i >= 0; $i--) {
            $iteration_number++;
            if (!is_array($AST[$i])) {
                $this->results[] = array(
                    self::ERROR_TEMPLATE_CLASS_CREATION
                );

                return null;
            }
            $value = (in_array(
                $AST[$i][self::AST_TYPE],
                self::TOKENS_POINTING_TO_ARGS
            )) ?
                '$this->TemplateEngine->resolveValue("' .
                $AST[$i][self::AST_VALUE] .
                '",$args,$parent,$i)' : $AST[$i][self::AST_VALUE];

            switch ($AST[$i][self::AST_TYPE]) {
                default:
                case self::TOKEN_TYPE_TEXT:
                    $code .= '$buffer.=' . var_export($value, true) . ';';
                    break;
                case self::TOKEN_TYPE_VAR:
                    $code .= '$buffer.=htmlspecialchars((string)' .
                             $value .
                             ");";
                    break;
                case self::TOKEN_TYPE_UNESCAPED_VAR:
                    $code .= '$buffer.=' . $value . ";";
                    break;
                case self::TOKEN_TYPE_LOOP_START:
                case self::TOKEN_TYPE_INVERTED_LOOP_START:
                    $function_name = $value . $iteration_number;
                    $code .= '$buffer.=$this->' .
                             $function_name .
                             '($args,$parent);';
                    $loop_parents[] = $AST[$i];
                    if (!is_array($AST[$i - 1])) {
                        $this->results[] = array(
                            self::ERROR_TEMPLATE_AST_INCONSISTENCY
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
                    $class_name = str_replace(
                        '.',
                        '_',
                        ltrim(
                            $value,
                            self::TOKEN_OPERATOR_DEREFERENCE
                        ) . $iteration_number
                    );
                    $iteration_number++;
                    $code .= '$' .
                             $class_name .
                             '=$this->TemplateEngine->loadTemplate($this->TemplateEngine->resolveValue("' .
                             $value .
                             '",$args,$parent,$i));if($' .
                             $class_name .
                             '!==null){$buffer.=$' .
                             $class_name .
                             '->render($args,$parent);}';
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
        }

        return array(
            self::INDEX_RENDER_BODY => array($code),
            self::INDEX_FUNCTIONS_CODE => $functions_code
        );
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
            $this->results[] = array(
                self::ERROR_TEMPLATE_EVALUATION,
                array("message" => $e->getMessage())
            );

            return false;
        }
    }
}
