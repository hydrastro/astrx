<?php
declare(strict_types=1);

namespace AstrX\Support;

/**
 * Where an installation keeps its files: the application's own resources
 * (the top layer over every package's, see ResourceRoots), its config,
 * templates, template cache and catalogs, and a directory for the few runtime
 * files kept outside it.
 *
 * The application builds one and registers it with the injector; every class
 * that reads or writes the installation's files takes it as a constructor
 * argument. Nothing in the packages reads a global for it. An empty directory
 * means the application has none: a class then uses only what it is given
 * (an explicit config file, its packages' resources) and writes nothing there.
 *
 * Every directory but $temp ends with a directory separator (or is ''), so a
 * file name can be appended directly: `$paths->config . 'Mail.config.php'`.
 */
final class Paths
{
    public readonly string $root;
    public readonly string $resources;
    public readonly string $config;
    public readonly string $templates;
    public readonly string $templateCache;
    public readonly string $lang;
    /** No trailing separator; '' means the system temp directory (see tempDir()). */
    public readonly string $temp;

    public function __construct(
        string $root = '',
        string $resources = '',
        string $config = '',
        string $templates = '',
        string $templateCache = '',
        string $lang = '',
        string $temp = '',
    ) {
        $this->root          = self::dir($root);
        $this->resources     = self::dir($resources);
        $this->config        = self::dir($config);
        $this->templates     = self::dir($templates);
        $this->templateCache = self::dir($templateCache);
        $this->lang          = self::dir($lang);
        // Trim BEFORE the emptiness test: '/' must become '' (the system temp
        // dir), never the filesystem root — see tempDir().
        $this->temp          = rtrim($temp, '/\\');
    }

    /**
     * The standard layout under an installation directory: resources/ with
     * config/, template/ (cache/ inside it) and lang/.
     */
    public static function standard(string $root, string $temp = ''): self
    {
        $root = self::dir($root);
        $res  = $root . 'resources' . DIRECTORY_SEPARATOR;
        $tpl  = $res . 'template' . DIRECTORY_SEPARATOR;
        return new self(
            root:          $root,
            resources:     $res,
            config:        $res . 'config' . DIRECTORY_SEPARATOR,
            templates:     $tpl,
            templateCache: $tpl . 'cache' . DIRECTORY_SEPARATOR,
            lang:          $res . 'lang' . DIRECTORY_SEPARATOR,
            temp:          $temp,
        );
    }

    /**
     * The paths a front controller declared as constants (src/paths.php:
     * INDEX_DIR, RESOURCES_DIR, CONFIG_DIR, TEMPLATE_DIR, TEMPLATE_CACHE_DIR,
     * LANG_DIR, and the optional ASTRX_TEMP_DIR). An undefined one is ''.
     * Only the application's entry points call this, once.
     */
    public static function fromConstants(): self
    {
        $c = static function (string $name): string {
            if (!defined($name)) {
                return '';
            }
            $v = \constant($name);
            return is_string($v) ? $v : '';
        };
        return new self(
            root:          $c('INDEX_DIR'),
            resources:     $c('RESOURCES_DIR'),
            config:        $c('CONFIG_DIR'),
            templates:     $c('TEMPLATE_DIR'),
            templateCache: $c('TEMPLATE_CACHE_DIR'),
            lang:          $c('LANG_DIR'),
            temp:          $c('ASTRX_TEMP_DIR'),
        );
    }

    /**
     * Where AstrX puts the few runtime files it keeps at a PREDICTABLE, SHARED
     * path outside the install: the generated server-secret fallback
     * (ServerSecret::TEMP_DIR_FILENAME) and the bot-trap tarpit slot locks.
     * The system temp directory unless the application set $temp — an operator
     * who wants these files off the world-writable /tmp, or a test that wants
     * them in its own scratch dir.
     *
     * A $temp that trims away (e.g. '/') counts as unset. Kept as the
     * filesystem root, ServerSecret::isSharedTempPath() — which short-circuits
     * on an empty temp dir — would skip the 0600 + ownership proof for the one
     * candidate it exists to protect, and write /astrx_server_secret.
     */
    public function tempDir(): string
    {
        return $this->temp !== '' ? $this->temp : sys_get_temp_dir();
    }

    /**
     * Resolve a writable storage directory portably.
     *
     * Prefers the configured path when it already exists, or when its parent
     * exists (so mkdir can create it) — this keeps a Docker "/app/resources/…"
     * mount working. Otherwise falls back to the application's resources dir
     * (<resources>/<subdir>), so a non-Docker deploy (CI, bare-metal) does not
     * fail every upload the way a hardcoded "/app/…" path does. Returns the
     * configured value unchanged as a last resort.
     */
    public function storageDir(string $configured, string $subdir): string
    {
        $configured = rtrim($configured, '/\\');
        if ($configured !== '') {
            if (is_dir($configured)) {
                return $configured;
            }
            $parent = \dirname($configured);
            if ($parent !== '' && $parent !== $configured && is_dir($parent)) {
                return $configured; // parent exists → mkdir($configured) can succeed
            }
        }
        $base = $this->resources !== '' ? $this->resources
            : ($this->root !== '' ? $this->root . 'resources' . DIRECTORY_SEPARATOR : '');
        if ($base !== '' && is_dir($base)) {
            return $base . $subdir;
        }
        return $configured;
    }

    private static function dir(string $dir): string
    {
        $dir = rtrim($dir, '/\\');
        return $dir === '' ? '' : $dir . DIRECTORY_SEPARATOR;
    }
}
