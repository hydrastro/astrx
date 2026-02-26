<?php
declare(strict_types=1);

namespace AstrX\Template;

use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkInterface;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use Throwable;
use AstrX\Result\DiagnosticSinkAwareInterface;
use AstrX\Result\DiagnosticSinkTrait;
use AstrX\Template\Diagnostic\InvalidDereferenceDiagnostic;
use AstrX\Template\Diagnostic\TemplateEvaluationDiagnostic;
use AstrX\Template\Diagnostic\TemplateFileNotFoundDiagnostic;
use AstrX\Template\Diagnostic\TemplateFileReadFailedDiagnostic;
use AstrX\Template\Diagnostic\UndefinedTokenArgumentDiagnostic;

final class TemplateEngine implements DiagnosticSinkAwareInterface
{
    use DiagnosticSinkTrait;
    // =========================
    // Diagnostic policy (IDs + Levels live here)
    // =========================
    public const ID_UNDEFINED_TOKEN_ARGUMENT = 'astrx.template/undefined_token_argument';
    public const LVL_UNDEFINED_TOKEN_ARGUMENT = DiagnosticLevel::NOTICE;

    public const ID_INVALID_DEREFERENCE = 'astrx.template/invalid_dereference';
    public const LVL_INVALID_DEREFERENCE = DiagnosticLevel::WARNING;

    public const ID_TEMPLATE_EVALUATION = 'astrx.template/template_evaluation_failed';
    public const LVL_TEMPLATE_EVALUATION = DiagnosticLevel::ERROR;

    public const ID_TEMPLATE_FILE_NOT_FOUND = 'astrx.template/template_file_not_found';
    public const LVL_TEMPLATE_FILE_NOT_FOUND = DiagnosticLevel::ERROR;

    public const ID_TEMPLATE_FILE_READ_FAILED = 'astrx.template/template_file_read_failed';
    public const LVL_TEMPLATE_FILE_READ_FAILED = DiagnosticLevel::ERROR;

    // =========================
    // Original constants (kept)
    // =========================
    public const PARSE_MODE_PLAIN = 0;
    public const PARSE_MODE_TEMPLATE = 1;

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

    public const TEMPLATE_OPEN_TAG = "{{";
    public const TEMPLATE_CLOSE_TAG = "}}";
    public const TEMPLATE_CLASS_PREFIX = "Template";

    public const AST_TYPE = 0;
    public const AST_VALUE = 1;

    public const INDEX_RENDER_BODY = 1;
    public const INDEX_FUNCTIONS_CODE = 2;

    /** @var array<string, mixed> */
    private array $globalArgs = [];

    private int $parseMode = self::PARSE_MODE_TEMPLATE;

    /** @var array<string, string> template name => class name */
    private array $knownTemplates = [];

    private string $templateDir;
    private string $templateExtension = ".html";
    private string $templateCacheDir;
    private bool $cacheTemplates = true;

    private DiagnosticSinkInterface $sink;

    public function __construct(?DiagnosticSinkInterface $sink = null)
    {
        // Default sink: local collector (safe standalone)
        $this->sink = $sink ?? new DiagnosticsCollector();

        $this->templateDir = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "template" . DIRECTORY_SEPARATOR;
        $this->templateCacheDir = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR;
    }

    public function setDiagnosticSink(DiagnosticSinkInterface $sink): void
    {
        $this->sink = $sink;
    }

    public function addGlobalArgs(array $args): void
    {
        $this->globalArgs = array_merge($this->globalArgs, $args);
    }

    public function setParseMode(int $parseMode): void
    {
        $this->parseMode = $parseMode;
    }

    public function setTemplateDir(string $dir): void { $this->templateDir = $dir; }
    public function setTemplateExtension(string $ext): void { $this->templateExtension = $ext; }
    public function setTemplateCacheDir(string $dir): void { $this->templateCacheDir = $dir; }
    public function setCacheTemplates(bool $cache): void { $this->cacheTemplates = $cache; }

    public function getTemplateDir(): string { return $this->templateDir; }
    public function getTemplateExtension(): string { return $this->templateExtension; }
    public function getTemplateCacheDir(): string { return $this->templateCacheDir; }
    public function getCacheTemplates(): bool { return $this->cacheTemplates; }
    public function getParseMode(): int { return $this->parseMode; }

    /**
     * CLEAN boundary API:
     * - creates a local collector
     * - runs compilation/rendering
     * - returns Result<string> carrying diagnostics snapshot
     *
     * @param array<string,mixed> $args
     * @param bool $phpProcessing
     * @return Result<string>
     */
    public function renderTemplate(string $template, array $args = [], bool $phpProcessing = false): Result
    {
        $collector = new DiagnosticsCollector();

        // temporarily route engine diagnostics to collector
        $prevSink = $this->sink;
        $this->sink = $collector;

        try {
            $tpl = $this->loadTemplate($template, $this->parseMode, $phpProcessing);
            if ($tpl === null) {
                // template load failure: diagnostics already emitted
                return Result::err('', $collector->diagnostics());
            }

            $mergedArgs = array_merge($args, $this->globalArgs);
            $out = $tpl->render($mergedArgs, []);

            return Result::ok((string)$out, $collector->diagnostics());
        } finally {
            $this->sink = $prevSink;
        }
    }

    /**
     * Resolve a value in the template context.
     *
     * @param array<string,mixed> $args
     * @param mixed $parent
     */
    public function resolveValue(string $value, array $args, mixed $parent = null, int $loopIndex = 0): mixed
    {
        if ($value === self::TOKEN_OPERATOR_DOT) {
            if (!is_array($parent) || !array_key_exists($loopIndex, $parent)) {
                $this->emitUndefined($value);
                return null;
            }
            return $parent[$loopIndex];
        }

        $result = null;

        if (is_array($parent) && $parent !== []) {
            if (array_key_exists(0, $parent) && is_array($parent[0])) {
                $parent = $parent[$loopIndex] ?? $parent;
            }
            $result = $this->resolveValueHelper($parent, $value);
        }

        if ($result === null) {
            $result = $this->resolveValueHelper($args, $value);
        }

        if ($result === null) {
            $this->emitUndefined($value);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $args
     */
    private function resolveValueHelper(array $args, string $value): mixed
    {
        $exploded = explode(self::TOKEN_OPERATOR_DOT, $value);
        $current = $args;

        // count deref stars at beginning of first segment
        $dereference = 0;
        $first = $exploded[0];
        for ($i = 0; $i < strlen($first); $i++) {
            if ($first[$i] === self::TOKEN_OPERATOR_DEREFERENCE) $dereference++;
            else break;
        }

        $exploded[0] = substr($first, $dereference);

        foreach ($exploded as $seg) {
            if (!is_array($current) || !array_key_exists($seg, $current)) {
                return null;
            }
            $current = $current[$seg];
        }

        for ($j = 0; $j < $dereference; $j++) {
            if (is_string($current) && array_key_exists($current, $args)) {
                $current = $args[$current];
            } else {
                $this->emitInvalidDereference($value);
                return null;
            }
        }

        return $current;
    }

    /**
     * Load/compile template and return template object instance, or null on failure.
     */
    public function loadTemplate(string $template, int $parseMode = self::PARSE_MODE_TEMPLATE, bool $phpProcessing = false): ?object
    {
        if (array_key_exists($template, $this->knownTemplates)) {
            return $this->getTemplateClass($this->knownTemplates[$template]);
        }

        $templateFile = $this->templateDir . $template . $this->templateExtension;
        if (!file_exists($templateFile)) {
            $this->sink->emit(new TemplateFileNotFoundDiagnostic(
                                  self::ID_TEMPLATE_FILE_NOT_FOUND,
                                  self::LVL_TEMPLATE_FILE_NOT_FOUND,
                                  $templateFile
                              ));
            return null;
        }

        $content = file_get_contents($templateFile);
        if ($content === false) {
            $this->sink->emit(new TemplateFileReadFailedDiagnostic(
                                  self::ID_TEMPLATE_FILE_READ_FAILED,
                                  self::LVL_TEMPLATE_FILE_READ_FAILED,
                                  $templateFile
                              ));
            return null;
        }

        $className = $this->getTemplateClassName($template, $content);

        if ($this->cacheTemplates) {
            $cacheFile = $this->templateCacheDir . $template . ".php";
            if (file_exists($cacheFile)) {
                require_once $cacheFile;
                $this->knownTemplates[$template] = $className;
                return $this->getTemplateClass($className);
            }
        }

        if ($parseMode === self::PARSE_MODE_TEMPLATE) {
            $tokenized = $this->tokenizeTemplate($content);
            $ast = $this->parseTemplate($tokenized);
            $code = $this->compileTemplate($className, $ast, $phpProcessing);
        } else {
            // plain mode
            $code = '<?php class ' . $className . '{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}function render($args=array(),$parent=array()){';
            if ($phpProcessing) {
                $code .= 'extract($args);ob_start();require("' . $this->templateDir . $template . '.php");$buffer=ob_get_clean();';
            } else {
                $code .= '$buffer=file_get_contents("' . $this->templateDir . $template . '.php");';
            }
            $code .= 'return ($buffer)?$buffer:"";}}';
        }

        if (!$this->evalTemplate($code) || !class_exists($className)) {
            return null;
        }

        $this->knownTemplates[$template] = $className;

        if ($this->cacheTemplates) {
            $cacheFile = $this->templateCacheDir . $template . ".php";
            @file_put_contents($cacheFile, $code);
        }

        return $this->getTemplateClass($className);
    }

    public function getTemplateClass(string $className): object
    {
        return new $className($this);
    }

    public function getTemplateClassName(string $templateName, string $templateContent): string
    {
        return self::TEMPLATE_CLASS_PREFIX
               . ltrim($templateName, self::TOKEN_OPERATOR_DEREFERENCE)
               . md5($templateContent);
    }

    /**
     * Tokenize template (same logic, but avoid asserts that crash).
     *
     * @return array<int, array<int, string>>
     */
    private function tokenizeTemplate(string $templateBody): array
    {
        $tokenized = [];

        $openTag = self::TEMPLATE_OPEN_TAG;
        $closeTag = self::TEMPLATE_CLOSE_TAG;

        $buffer = "";
        $type = self::TOKEN_TYPE_TEXT;

        $len = strlen($templateBody);
        $unclosed = false;

        for ($i = 1; $i < $len; $i++) {
            $i--;

            $closeLen = strlen($closeTag);
            if (substr($templateBody, $i, $closeLen) === $closeTag) {
                if ($type === self::TOKEN_TYPE_CHANGE_TAGS) {
                    $tags = explode(" ", $buffer);
                    if (count($tags) === 2 && $tags[0] !== $tags[1]) {
                        $openTag = $tags[0];
                        $closeTag = $tags[1];
                    }
                }
                $unclosed = false;
                $type = self::TOKEN_TYPE_TEXT;
                $i += $closeLen;
            }

            $openLen = strlen($openTag);
            if (substr($templateBody, $i, $openLen) === $openTag) {
                // if already unclosed, we just continue; malformed templates are allowed but may render oddly
                $unclosed = true;

                $next = $templateBody[$i + $openLen] ?? '';
                if (in_array($next, [self::TOKEN_TYPE_CHANGE_TAGS, self::TOKEN_TYPE_COMMENT, self::TOKEN_TYPE_PARTIAL, self::TOKEN_TYPE_INVERTED_LOOP_START, self::TOKEN_TYPE_LOOP_END, self::TOKEN_TYPE_LOOP_START, self::TOKEN_TYPE_UNESCAPED_VAR], true)) {
                    $type = $next;
                    $i += 1;
                } else {
                    $type = self::TOKEN_TYPE_VAR;
                }
                $i += $openLen;
            }

            $buffer = "";
            $closeLen = strlen($closeTag);
            $openLen = strlen($openTag);

            while (
                $i < $len
                && substr($templateBody, $i, $closeLen) !== $closeTag
                && substr($templateBody, $i, $openLen) !== $openTag
            ) {
                $buffer .= $templateBody[$i];
                $i++;
            }

            if ($unclosed) {
                $buffer = trim($buffer);
                if ($type === self::TOKEN_TYPE_CHANGE_TAGS) {
                    if (substr($buffer, -1) === self::TOKEN_TYPE_CHANGE_TAGS) {
                        $buffer = rtrim($buffer, self::TOKEN_TYPE_CHANGE_TAGS);
                    }
                }
            }

            if ($buffer === "") {
                continue;
            }

            $tokenized[] = [
                self::AST_TYPE => $type,
                self::AST_VALUE => $buffer
            ];
        }

        // if unclosed, ignore; rendering may be partial but we do not crash
        return $tokenized;
    }

    /**
     * Parse template to AST (kept mostly as-is, but avoid asserts that crash).
     *
     * @param array<int, array<int, string>> $tokenized
     * @param int $index
     * @param array<int, string> $unclosedLoops
     * @return array<int, mixed>
     */
    private function parseTemplate(array $tokenized, int &$index = -1, array &$unclosedLoops = []): array
    {
        $ast = [];

        $index = ($index === -1) ? count($tokenized) - 1 : $index;

        for ($i = &$index; $i >= 0; $i--) {
            $type = $tokenized[$i][self::AST_TYPE];
            $value = $tokenized[$i][self::AST_VALUE];

            if ($type === self::TOKEN_TYPE_LOOP_END) {
                $unclosedLoops[] = $value;
                $i--;

                $result = $this->parseTemplate($tokenized, $i, $unclosedLoops);
                if ($result !== []) {
                    $ast[] = $result;
                    $ast[] = $tokenized[$i] ?? [];
                }
            } elseif ($type === self::TOKEN_TYPE_LOOP_START || $type === self::TOKEN_TYPE_INVERTED_LOOP_START) {
                $expected = end($unclosedLoops);
                if ($expected !== $value) {
                    // mismatch: stop closing; treat as plain tokens
                    return array_merge([[
                                            self::AST_TYPE => self::TOKEN_TYPE_LOOP_END,
                                            self::AST_VALUE => (string)$expected
                                        ]], $ast);
                }
                array_pop($unclosedLoops);

                return array_merge([[
                                        self::AST_TYPE => self::TOKEN_TYPE_LOOP_END,
                                        self::AST_VALUE => $value
                                    ]], $ast);
            } else {
                $ast[] = $tokenized[$i];
            }
        }

        // ignore leftover unclosed loops rather than crashing
        $unclosedLoops = [];

        return $ast;
    }

    private function compileTemplate(string $className, array $ast, bool $phpProcessing): string
    {
        $code = $this->writeCode($ast);

        $class = "<?php class " . $className
                 . '{private $TemplateEngine;function __construct($TemplateEngine){$this->TemplateEngine=$TemplateEngine;}'
                 . 'function render($args=array(),$parent=array()){$buffer="";'
                 . $code[self::INDEX_RENDER_BODY][0];

        if ($phpProcessing) {
            $class .= 'extract($args);ob_start();eval("?>" . $buffer);$buffer=ob_get_clean();';
        }

        $class .= 'return ($buffer) ? $buffer : "";}' . implode("", $code[self::INDEX_FUNCTIONS_CODE]) . "}";

        return $class;
    }

    /**
     * Largely original writeCode() logic.
     *
     * @return array<int, array<int, string>>
     */
    private function writeCode(array $ast, array $loopParents = [], array &$functionsCode = [], int $iteration = 0): array
    {
        $code = '';

        if ($loopParents !== []) {
            $endParent = end($loopParents);
            $code .= "function " . ltrim($endParent[self::AST_VALUE], self::TOKEN_OPERATOR_DEREFERENCE)
                     . $iteration . '($args,$parent,$i){$buffer="";';

            $code .= match ($endParent[self::AST_TYPE]) {
                default => '$resolved=$this->TemplateEngine->resolveValue("'
                           . $endParent[self::AST_VALUE]
                           . '",$args,$parent,$i);if(is_countable($resolved)){$count=count($resolved);}elseif($resolved){$count=1;}else{$count=0;}$parent=$resolved;for($i=0;$i<$count;$i++){',
                self::TOKEN_TYPE_INVERTED_LOOP_START => '$resolved=$this->TemplateEngine->resolveValue("'
                                                        . $endParent[self::AST_VALUE]
                                                        . '",$args,$parent,$i);if(!$resolved){'
            };
        } else {
            $code .= '$i=0;';
        }

        for ($i = count($ast) - 1; $i >= 0; $i--) {
            $iteration++;

            if (!is_array($ast[$i])) continue;

            $type = $ast[$i][self::AST_TYPE] ?? self::TOKEN_TYPE_TEXT;
            $val = $ast[$i][self::AST_VALUE] ?? '';

            $valueExpr = in_array($type, [self::TOKEN_TYPE_VAR, self::TOKEN_TYPE_UNESCAPED_VAR], true)
                ? '$this->TemplateEngine->resolveValue("' . $val . '",$args,$parent,$i)'
                : $val;

            switch ($type) {
                default:
                case self::TOKEN_TYPE_TEXT:
                    $code .= '$buffer.=' . var_export($valueExpr, true) . ';';
                    break;

                case self::TOKEN_TYPE_VAR:
                    $code .= '$buffer.=htmlspecialchars((string)' . $valueExpr . ");";
                    break;

                case self::TOKEN_TYPE_UNESCAPED_VAR:
                    $code .= '$buffer.=' . $valueExpr . ";";
                    break;

                case self::TOKEN_TYPE_INVERTED_LOOP_START:
                case self::TOKEN_TYPE_LOOP_START:
                    $functionName = $valueExpr . $iteration;
                    $code .= '$buffer.=$this->' . $functionName . '($args,$parent,$i);';
                    $loopParents[] = $ast[$i];
                    $this->writeCode($ast[$i - 1] ?? [], $loopParents, $functionsCode, $iteration);
                    array_pop($loopParents);
                    $i--;
                    break;

                case self::TOKEN_TYPE_PARTIAL:
                    $classVar = str_replace('.', '_', ltrim($valueExpr, self::TOKEN_OPERATOR_DEREFERENCE) . $iteration);
                    $iteration++;

                    $code .= '$' . $classVar . '=$this->TemplateEngine->loadTemplate($this->TemplateEngine->resolveValue("'
                             . $valueExpr . '",$args,$parent,$i));if($' . $classVar . '!==null){$buffer.=$'
                             . $classVar . '->render($args,$parent);}';
                    break;

                case self::TOKEN_TYPE_LOOP_END:
                case self::TOKEN_TYPE_COMMENT:
                case self::TOKEN_TYPE_CHANGE_TAGS:
                    break;
            }
        }

        if ($loopParents !== []) {
            $code .= '} return $buffer;}';
            $functionsCode[] = $code;
        }

        return [
            self::INDEX_RENDER_BODY => [$code],
            self::INDEX_FUNCTIONS_CODE => $functionsCode
        ];
    }

    private function evalTemplate(string $code): bool
    {
        try {
            eval("?>" . $code);
            return true;
        } catch (Throwable $e) {
            $this->sink->emit(new TemplateEvaluationDiagnostic(
                                  self::ID_TEMPLATE_EVALUATION,
                                  self::LVL_TEMPLATE_EVALUATION,
                                  $e->getMessage()
                              ));
            return false;
        }
    }

    private function emitUndefined(string $token): void
    {
        $this->sink->emit(new UndefinedTokenArgumentDiagnostic(
                              self::ID_UNDEFINED_TOKEN_ARGUMENT,
                              self::LVL_UNDEFINED_TOKEN_ARGUMENT,
                              $token
                          ));
    }

    private function emitInvalidDereference(string $value): void
    {
        $this->sink->emit(new InvalidDereferenceDiagnostic(
                              self::ID_INVALID_DEREFERENCE,
                              self::LVL_INVALID_DEREFERENCE,
                              $value
                          ));
    }
}