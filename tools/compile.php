#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * AstrX production compiler.
 *
 * Generates a single PHP bundle containing all AstrX source files plus a
 * read-only text-resource payload for templates/lang/theme assets.
 *
 * Usage:
 *   php tools/compile.php
 *   php tools/compile.php --out=build/astrx.compiled.php --front=public/compiled.php
 */

final class AstrXCompiler
{
    private string $root;
    private string $outFile;
    private string $frontController;
    private string $compileFrontController;

    /** @var array<string,string> */
    private array $sourcePayload = [];

    /** @var array<string,string> */
    private array $classMap = [];

    /** @var array<string,string> */
    private array $resourcePayload = [];

    /** @var array<string,array{bytes:int,sha256:string}> */
    private array $resourceManifest = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @param list<string> $argv */
    public function __construct(array $argv)
    {
        $this->root = dirname(__DIR__);
        $this->outFile = $this->root . '/build/astrx.compiled.php';
        $this->frontController = $this->root . '/public/compiled.php';
        $this->compileFrontController = $this->root . '/public/compile/index.php';

        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--root=')) {
                $this->root = rtrim(substr($arg, 7), DIRECTORY_SEPARATOR);
                $this->outFile = $this->root . '/build/astrx.compiled.php';
                $this->frontController = $this->root . '/public/compiled.php';
                $this->compileFrontController = $this->root . '/public/compile/index.php';
                continue;
            }
            if (str_starts_with($arg, '--out=')) {
                $this->outFile = $this->absoluteOrRoot(substr($arg, 6));
                continue;
            }
            if (str_starts_with($arg, '--front=')) {
                $this->frontController = $this->absoluteOrRoot(substr($arg, 8));
                continue;
            }
            if (str_starts_with($arg, '--compile-front=')) {
                $this->compileFrontController = $this->absoluteOrRoot(substr($arg, 16));
                continue;
            }
            if ($arg === '-h' || $arg === '--help') {
                $this->usageAndExit();
            }
            fwrite(STDERR, "Unknown option: {$arg}\n");
            $this->usageAndExit(1);
        }
    }

    public function run(): void
    {
        $this->collectSource();
        $this->collectResources();
        $this->writeBundle();
        $this->writeFrontController();
        $this->writeCompileFrontController();
        $this->warmTemplateCache();
        $this->lint($this->outFile);
        $this->lint($this->frontController);
        $this->lint($this->compileFrontController);

        $sourceBytes = filesize($this->outFile) ?: 0;
        $frontBytes  = filesize($this->frontController) ?: 0;
        $compileFrontBytes = filesize($this->compileFrontController) ?: 0;

        echo "AstrX compiled bundle written.\n";
        echo "  bundle:  " . $this->relative($this->outFile) . ' (' . $this->fmtBytes($sourceBytes) . ")\n";
        echo "  front:   " . $this->relative($this->frontController) . ' (' . $this->fmtBytes($frontBytes) . ")\n";
        echo "  prefix:  " . $this->relative($this->compileFrontController) . ' (' . $this->fmtBytes($compileFrontBytes) . ")\n";
        echo "  classes: " . count($this->classMap) . "\n";
        echo "  source files: " . count($this->sourcePayload) . "\n";
        echo "  embedded resources: " . count($this->resourcePayload) . "\n";

        foreach ($this->warnings as $warning) {
            echo "  warning: {$warning}\n";
        }
    }

    private function collectSource(): void
    {
        $src = $this->root . '/src';
        if (!is_dir($src)) {
            throw new RuntimeException("Missing source directory: {$src}");
        }

        $files = $this->phpFiles($src);
        usort($files, static function (SplFileInfo $a, SplFileInfo $b): int {
            return strcmp($a->getPathname(), $b->getPathname());
        });

        foreach ($files as $file) {
            $path = $file->getPathname();
            $rel  = $this->slash($this->relative($path));
            $raw  = file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException("Could not read {$rel}");
            }

            $this->sourcePayload[$rel] = $this->stripPhpPreamble($raw);

            foreach ($this->symbolsIn($raw) as $symbol) {
                if (isset($this->classMap[$symbol])) {
                    $this->warnings[] = "duplicate symbol {$symbol} in {$rel}; already mapped to {$this->classMap[$symbol]}";
                    continue;
                }
                $this->classMap[$symbol] = $rel;
            }
        }

        if (!isset($this->sourcePayload['src/AstrX/Prelude.php'])) {
            throw new RuntimeException('Prelude.php was not included in the bundle.');
        }
        if (!isset($this->sourcePayload['src/AstrX/Support/constants.php'])) {
            throw new RuntimeException('Support/constants.php was not included in the bundle.');
        }
    }

    private function collectResources(): void
    {
        $resourceRoots = [
            'resources/lang',
            'resources/template',
            'setup',
            'src/setup',
        ];

        foreach ($resourceRoots as $rootRel) {
            $root = $this->root . '/' . $rootRel;
            if (!is_dir($root)) {
                continue;
            }

            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($rii as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();
                $rel  = $this->slash($this->relative($path));

                if ($this->shouldSkipResource($rel)) {
                    continue;
                }

                $content = file_get_contents($path);
                if ($content === false) {
                    throw new RuntimeException("Could not read resource {$rel}");
                }

                $this->resourcePayload[$rel] = $content;
                $this->resourceManifest[$rel] = [
                    'bytes'  => strlen($content),
                    'sha256' => hash('sha256', $content),
                ];
            }
        }
    }

    private function shouldSkipResource(string $rel): bool
    {
        if (str_starts_with($rel, 'resources/template/cache/')) {
            return true;
        }
        if (str_starts_with($rel, 'resources/fonts/')) {
            return true;
        }
        if (str_starts_with($rel, 'resources/config/')) {
            return true;
        }
        if (str_contains($rel, '/.')) {
            return true;
        }
        return false;
    }

    private function writeBundle(): void
    {
        $dir = dirname($this->outFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }

        $version = date('YmdHis') . '-' . substr(hash('sha256', json_encode([
            $this->classMap,
            $this->resourceManifest,
        ], JSON_THROW_ON_ERROR)), 0, 12);

        $sourceExport   = var_export($this->sourcePayload, true);
        $classMapExport = var_export($this->classMap, true);
        $resExport      = var_export($this->resourcePayload, true);
        $manifestExport = var_export($this->resourceManifest, true);

        $bundle = <<<PHP
<?php
declare(strict_types=1);

/**
 * Generated AstrX compiled bundle.
 *
 * Do not edit this file manually. Rebuild with:
 *   php tools/compile.php
 */

namespace AstrX\Compiled;

final class Bundle
{
    public const VERSION = '{$version}';
    public const MODE = 'compiled-bundle';

    /** @var array<string,string> */
    private const CLASS_MAP = {$classMapExport};

    /** @var array<string,string> */
    private const SOURCE = {$sourceExport};

    /** @var array<string,string> */
    private const RESOURCES = {$resExport};

    /** @var array<string,array{bytes:int,sha256:string}> */
    private const RESOURCE_MANIFEST = {$manifestExport};

    /** @var array<string,true> */
    private static array \$loadedFiles = [];

    private static bool \$registered = false;

    public static function register(): void
    {
        if (self::\$registered) {
            return;
        }
        self::\$registered = true;

        spl_autoload_register([self::class, 'autoload'], prepend: true);
        self::loadFile('src/AstrX/Support/constants.php');
    }

    public static function boot(): void
    {
        self::register();
        new \AstrX\Prelude();
    }

    public static function autoload(string \$class): void
    {
        if (!isset(self::CLASS_MAP[\$class])) {
            return;
        }
        self::loadFile(self::CLASS_MAP[\$class]);
    }

    public static function loadFile(string \$path): void
    {
        if (isset(self::\$loadedFiles[\$path])) {
            return;
        }
        if (!isset(self::SOURCE[\$path])) {
            throw new \RuntimeException('Compiled AstrX source file not found: ' . \$path);
        }

        self::\$loadedFiles[\$path] = true;
        eval(self::SOURCE[\$path]);
    }

    /** @return array<string,array{bytes:int,sha256:string}> */
    public static function resourceManifest(): array
    {
        return self::RESOURCE_MANIFEST;
    }

    public static function resource(string \$relativePath): ?string
    {
        \$relativePath = str_replace('\\\\', '/', ltrim(\$relativePath, '/'));
        return self::RESOURCES[\$relativePath] ?? null;
    }

    /**
     * Materialise embedded read-only resources into a resources directory.
     *
     * Config files, upload state, cache files, and fonts are deliberately not
     * embedded. They stay external because they are environment-specific or
     * mutable. Use this for minimal deployments where templates/lang/setup SQL
     * should be restored beside the compiled bundle.
     */
    public static function extractResources(string \$projectRoot, bool \$overwrite = false): int
    {
        \$projectRoot = rtrim(\$projectRoot, DIRECTORY_SEPARATOR);
        \$written = 0;

        foreach (self::RESOURCES as \$relativePath => \$content) {
            \$target = \$projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, \$relativePath);
            if (is_file(\$target) && !\$overwrite) {
                continue;
            }

            \$dir = dirname(\$target);
            if (!is_dir(\$dir) && !mkdir(\$dir, 0775, true) && !is_dir(\$dir)) {
                throw new \RuntimeException('Could not create resource directory: ' . \$dir);
            }

            if (file_put_contents(\$target, \$content) === false) {
                throw new \RuntimeException('Could not write resource: ' . \$target);
            }
            \$written++;
        }

        return \$written;
    }
}

Bundle::register();
PHP;

        file_put_contents($this->outFile, $bundle);
    }


    private function warmTemplateCache(): void
    {
        $script = $this->root . '/tools/warm-template-cache.php';
        if (!is_file($script)) {
            $this->warnings[] = 'template cache warm skipped; tools/warm-template-cache.php not found';
            return;
        }

        $cmd = PHP_BINARY . ' ' . escapeshellarg($script) . ' --quiet 2>&1';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            $this->warnings[] = 'template cache warm failed: ' . implode(' | ', $out);
            return;
        }

        $this->warnings[] = 'server-side template cache warmed';
    }

    private function writeFrontController(): void
    {
        $this->writeFrontControllerFile($this->frontController, null);
    }

    private function writeCompileFrontController(): void
    {
        $this->writeFrontControllerFile($this->compileFrontController, '/compile');
    }

    private function writeFrontControllerFile(string $target, ?string $prefix): void
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }

        $prefixExport = var_export($prefix, true);

        $front = <<<PHP
<?php

declare(strict_types=1);

// @phpstan-ignore-file

/**
 * AstrX compiled front controller.
 *
 * Generate/update the bundle with:
 *   php tools/compile.php
 *
 * In production you can point the web server to this file instead of
 * public/index.php. It keeps the normal resources/config directory external
 * while loading AstrX PHP classes from build/astrx.compiled.php.
 */

define(
    'INDEX_DIR',
    realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . (basename(__DIR__) === 'compile' ? '..' : '')) . DIRECTORY_SEPARATOR
);
const RESOURCES_DIR = INDEX_DIR . 'resources' . DIRECTORY_SEPARATOR;
const LANG_DIR = RESOURCES_DIR . 'lang' . DIRECTORY_SEPARATOR;
const CONFIG_DIR = RESOURCES_DIR . 'config' . DIRECTORY_SEPARATOR;
const TEMPLATE_DIR = RESOURCES_DIR . 'template' . DIRECTORY_SEPARATOR;
const TEMPLATE_CACHE_DIR = TEMPLATE_DIR . 'cache' . DIRECTORY_SEPARATOR;
const SRC_DIR = INDEX_DIR . 'src' . DIRECTORY_SEPARATOR;
const CLASS_DIR = SRC_DIR . 'AstrX' . DIRECTORY_SEPARATOR;
const CONTROLLER_DIR = SRC_DIR . 'controller' . DIRECTORY_SEPARATOR;
const TEMPLATE_HANDLER_DIR = SRC_DIR . 'template_handler' . DIRECTORY_SEPARATOR;

set_include_path(__DIR__);

\$compilePrefix = {$prefixExport};
if (\$compilePrefix !== null) {
    define('ASTRX_COMPILED_ROUTE_PREFIX', \$compilePrefix);
    \$_SERVER['ASTRX_COMPILED_MODE'] = '1';
    \$_SERVER['ASTRX_COMPILED_ROUTE_PREFIX'] = \$compilePrefix;

    \$astrxPrefixPath = static function (string \$path) use (\$compilePrefix): string {
        if (\$path === '' || \$path[0] !== '/') {
            return \$path;
        }
        if (\$path === \$compilePrefix || str_starts_with(\$path, \$compilePrefix . '/')) {
            return \$path;
        }
        if (preg_match('#^/(?:[a-z]{2})(?:/|\$)#i', \$path) === 1 || \$path === '/') {
            return rtrim(\$compilePrefix, '/') . (\$path === '/' ? '' : \$path);
        }
        return \$path;
    };

    \$astrxStripPrefix = static function (string \$uri) use (\$compilePrefix): string {
        \$parts = parse_url(\$uri);
        \$path = is_array(\$parts) && isset(\$parts['path']) ? (string) \$parts['path'] : \$uri;
        \$query = is_array(\$parts) && isset(\$parts['query']) ? '?' . (string) \$parts['query'] : '';

        if (\$path === \$compilePrefix) {
            return '/' . \$query;
        }
        if (str_starts_with(\$path, \$compilePrefix . '/')) {
            \$stripped = substr(\$path, strlen(\$compilePrefix));
            return (\$stripped !== '' ? \$stripped : '/') . \$query;
        }
        return \$uri;
    };

    \$originalRequestUri = \$_SERVER['REQUEST_URI'] ?? '/';
    \$_SERVER['ASTRX_ORIGINAL_REQUEST_URI'] = \$originalRequestUri;
    \$_SERVER['REQUEST_URI'] = \$astrxStripPrefix(\$originalRequestUri);
    \$_SERVER['SCRIPT_NAME'] = \$compilePrefix . '/index.php';
    \$_SERVER['PHP_SELF'] = \$compilePrefix . '/index.php';

    if (!headers_sent()) {
        header('X-AstrX-Compiled: prefix=' . \$compilePrefix);
    }

    header_register_callback(static function () use (\$compilePrefix, \$astrxPrefixPath): void {
        foreach (headers_list() as \$headerLine) {
            if (stripos(\$headerLine, 'Location:') !== 0) {
                continue;
            }
            \$location = trim(substr(\$headerLine, 9));
            \$newLocation = \$location;

            if (str_starts_with(\$location, '/')) {
                \$newLocation = \$astrxPrefixPath(\$location);
            } else {
                \$parts = parse_url(\$location);
                \$host = \$_SERVER['HTTP_HOST'] ?? '';
                if (is_array(\$parts) && isset(\$parts['host'], \$parts['path']) && strcasecmp((string) \$parts['host'], \$host) === 0) {
                    \$path = \$astrxPrefixPath((string) \$parts['path']);
                    \$query = isset(\$parts['query']) ? '?' . (string) \$parts['query'] : '';
                    \$fragment = isset(\$parts['fragment']) ? '#' . (string) \$parts['fragment'] : '';
                    \$scheme = isset(\$parts['scheme']) ? (string) \$parts['scheme'] : 'http';
                    \$newLocation = \$scheme . '://' . \$host . \$path . \$query . \$fragment;
                }
            }

            if (\$newLocation !== \$location) {
                header_remove('Location');
                header('Location: ' . \$newLocation, true, http_response_code() ?: 302);
            }
        }
    });

    ob_start(static function (string \$html) use (\$compilePrefix, \$astrxPrefixPath): string {
        \$contentType = '';
        foreach (headers_list() as \$headerLine) {
            if (stripos(\$headerLine, 'Content-Type:') === 0) {
                \$contentType = strtolower(\$headerLine);
                break;
            }
        }
        if (\$contentType !== '' && !str_contains(\$contentType, 'text/html')) {
            return \$html;
        }

        \$rewriteAttr = static function (array \$m) use (\$astrxPrefixPath): string {
            \$url = html_entity_decode(\$m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            \$parts = parse_url(\$url);
            \$path = is_array(\$parts) && isset(\$parts['path']) ? (string) \$parts['path'] : \$url;
            if (\$path === '' || \$path[0] !== '/') {
                return \$m[0];
            }
            \$query = is_array(\$parts) && isset(\$parts['query']) ? '?' . (string) \$parts['query'] : '';
            \$fragment = is_array(\$parts) && isset(\$parts['fragment']) ? '#' . (string) \$parts['fragment'] : '';
            \$rewritten = \$astrxPrefixPath(\$path) . \$query . \$fragment;
            if (\$rewritten === \$url) {
                return \$m[0];
            }
            return \$m[1] . '=' . \$m[2] . htmlspecialchars(\$rewritten, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . \$m[2];
        };

        \$html = preg_replace_callback('/\b(href|src|action|formaction|poster)=("|\')([^"\']*)\\2/i', \$rewriteAttr, \$html) ?? \$html;
        return \$html;
    });
}

\$bundle = INDEX_DIR . 'build' . DIRECTORY_SEPARATOR . 'astrx.compiled.php';
if (!is_file(\$bundle)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "AstrX compiled bundle is missing. Run: php tools/compile.php\n";
    exit;
}

require \$bundle;
\AstrX\Compiled\Bundle::boot();
PHP;

        file_put_contents($target, $front);
    }

    /** @return list<SplFileInfo> */
    private function phpFiles(string $dir): array
    {
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($rii as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }
        return $files;
    }

    /** @return list<string> */
    private function symbolsIn(string $php): array
    {
        $tokens = token_get_all($php);
        $namespace = '';
        $symbols = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if ($t === ';' || $t === '{') {
                        break;
                    }
                    if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $t[1];
                    }
                }
                continue;
            }

            if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            if ($token[0] === T_CLASS) {
                $prev = $this->previousMeaningfulTokenId($tokens, $i);
                if ($prev === T_NEW || $prev === T_DOUBLE_COLON) {
                    continue; // anonymous class or Foo::class
                }
            }

            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && $t[0] === T_STRING) {
                    $name = $t[1];
                    break;
                }
            }

            if ($name !== null) {
                $symbols[] = ltrim($namespace . '\\' . $name, '\\');
            }
        }

        return $symbols;
    }

    /** @param array<int,mixed> $tokens */
    /** @param list<string|array{0:int,1:string,2?:int}> $tokens */
    private function previousMeaningfulTokenId(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $t = $tokens[$i];
            if (is_array($t)) {
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return is_int($t[0]) ? $t[0] : null;
            }
            if (trim($t) === '') {
                continue;
            }
            return null;
        }
        return null;
    }

    private function stripPhpPreamble(string $php): string
    {
        $php = preg_replace('/^\xEF\xBB\xBF/', '', $php) ?? $php;
        $php = preg_replace('/^\s*<\?php\s*/', '', $php, 1) ?? $php;
        $php = preg_replace('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/i', '', $php, 1) ?? $php;
        $php = preg_replace('/\?>\s*$/', '', $php, 1) ?? $php;
        return $php;
    }

    private function lint(string $file): void
    {
        $cmd = 'php -l ' . escapeshellarg($file) . ' 2>&1';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            throw new RuntimeException("PHP lint failed for {$file}:\n" . implode("\n", $out));
        }
    }

    private function absoluteOrRoot(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('Empty path option.');
        }
        if ($path[0] === DIRECTORY_SEPARATOR) {
            return $path;
        }
        return $this->root . DIRECTORY_SEPARATOR . $path;
    }

    private function relative(string $path): string
    {
        $root = rtrim(realpath($this->root) ?: $this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $real = realpath($path) ?: $path;
        if (str_starts_with($real, $root)) {
            return substr($real, strlen($root));
        }
        return $path;
    }

    private function slash(string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }

    private function fmtBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MiB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KiB';
        }
        return $bytes . ' B';
    }

    private function usageAndExit(int $code = 0): void
    {
        $msg = <<<'TXT'
Usage:
  php tools/compile.php [--root=/path/to/repo] [--out=build/astrx.compiled.php] [--front=public/compiled.php] [--compile-front=public/compile/index.php]

Generates:
  build/astrx.compiled.php       Single PHP source bundle + embedded text resources
  public/compiled.php            Direct front controller that boots from the bundle
  public/compile/index.php       /compile-prefixed benchmark front controller
TXT;
        fwrite($code === 0 ? STDOUT : STDERR, $msg . PHP_EOL);
        exit($code);
    }
}

try {
    (new AstrXCompiler($argv))->run();
} catch (Throwable $e) {
    fwrite(STDERR, "compile failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
