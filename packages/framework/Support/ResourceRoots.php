<?php
declare(strict_types=1);

namespace AstrX\Support;

/**
 * The resource directories the application's packages and installed modules
 * ship, in precedence order (lowest first): the core packages' (framework,
 * library, CMS), then the modules'.
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
 *   - templates: the site's file, else the first directory that has one;
 *   - catalogs and config: these files first, in order, then the site's, so
 *     the site's keys override the package defaults key by key;
 *   - writes (admin config, the language editor): the site's files only. A
 *     package's directory is read-only — installed with Composer it lives
 *     under vendor/.
 *
 * Built once by the composition root (ApplicationContainer::resourceRoots())
 * and shared through the injector.
 */
final class ResourceRoots
{
    public const string TEMPLATE = 'template';
    public const string LANG     = 'lang';
    public const string CONFIG   = 'config';
    public const string SQL      = 'sql';

    /** @var list<string> */
    private readonly array $dirs;

    /** @param list<string> $dirs resource directories, lowest precedence first */
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
     * The resource directories themselves.
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
