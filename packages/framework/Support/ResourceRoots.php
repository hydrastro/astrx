<?php
declare(strict_types=1);

namespace AstrX\Support;

/**
 * The resource directories the installed modules ship, in precedence order
 * (lowest first).
 *
 * Each directory has the same layout as the site's own resources/:
 *
 *   <root>/template/…            templates, by the same logical names
 *   <root>/lang/<locale>/…       translation catalogs (and Diagnostics/)
 *   <root>/config/…              default configuration
 *   <root>/sql/…                 install / teardown / migration scripts
 *
 * The site's own resources/ directory is NOT in this list: it is the top layer
 * and always wins. Consumers combine the two the same way:
 *
 *   - templates: the site's file, else the first module that has one;
 *   - catalogs and config: module files first, then the site's, so the site's
 *     keys override the module defaults key by key;
 *   - writes (admin config, the language editor): the site's files only. A
 *     module's directory is read-only — once modules are Composer packages it
 *     lives under vendor/.
 *
 * Built once by the composition root from the installed modules
 * (ModuleRegistry::resourceRootsFor()) and shared through the injector.
 */
final class ResourceRoots
{
    public const string TEMPLATE = 'template';
    public const string LANG     = 'lang';
    public const string CONFIG   = 'config';
    public const string SQL      = 'sql';

    /** @var list<string> */
    private readonly array $dirs;

    /** @param list<string> $dirs module resource directories, lowest precedence first */
    public function __construct(array $dirs = [])
    {
        $clean = [];
        foreach ($dirs as $dir) {
            $dir = rtrim($dir, '/\\');
            if ($dir !== '' && !in_array($dir, $clean, true)) {
                $clean[] = $dir;
            }
        }
        $this->dirs = $clean;
    }

    /**
     * The module resource directories themselves.
     *
     * @return list<string>
     */
    public function dirs(): array
    {
        return $this->dirs;
    }

    /**
     * The existing <root>/<kind> directories, lowest precedence first, each with
     * a trailing separator.
     *
     * @return list<string>
     */
    public function of(string $kind): array
    {
        $out = [];
        foreach ($this->dirs as $dir) {
            $path = $dir . DIRECTORY_SEPARATOR . $kind;
            if (is_dir($path)) {
                $out[] = $path . DIRECTORY_SEPARATOR;
            }
        }
        return $out;
    }
}
