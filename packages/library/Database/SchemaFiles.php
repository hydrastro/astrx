<?php
declare(strict_types=1);

namespace AstrX\Database;

/**
 * Which SQL files make up the database, and in what order.
 *
 * The application keeps its own SQL in src/setup/; each package with SQL — the
 * CMS, which owns the core tables, and every installed module — in its own
 * resources/sql/. Three kinds of file, found the same way in both places:
 *
 *   schema/NNNN_<owner>_<what>.sql
 *       The base schema, in pieces. The installer applies EVERY piece on every
 *       run (like the single tables.sql it replaces), so a piece is written to
 *       be re-applied: CREATE TABLE fails harmlessly on an existing table
 *       (SQLSTATE 42S01) and seed rows use INSERT IGNORE. Pieces from core and
 *       from modules are merged and sorted by FILE NAME, so the number places a
 *       module's piece among core's (0000 is the core base; the Webmail
 *       module's 0005 runs right after it).
 *
 *   migrate_<name>.sql
 *       A migration. Applied once and recorded in the `migration` table by
 *       file name and checksum, so a migration that has shipped must never be
 *       edited or renamed — it would abort the next upgrade. Core's and the
 *       modules' are merged and sorted by file name too.
 *
 *   <key>.down.sql  (modules only, named by the manifest's 'teardown')
 *       What `tools/module.php purge` runs to remove a module's data.
 *
 * A module's file name may not repeat one of core's or another module's: two
 * pieces or two migrations with the same name would make the order (and the
 * ledger) ambiguous, so that throws.
 */
final class SchemaFiles
{
    /**
     * @param list<string> $coreDirs      core SQL directories, e.g. [<root>/src/setup]
     * @param list<string> $moduleSqlDirs each installed module's resources/sql directory
     */
    public function __construct(
        private readonly array $coreDirs,
        private readonly array $moduleSqlDirs = [],
    ) {}

    /**
     * The base schema pieces, in the order the installer applies them.
     *
     * @return list<string> absolute paths
     */
    public function schema(): array
    {
        return $this->collect('schema' . DIRECTORY_SEPARATOR . '*.sql');
    }

    /**
     * Every migration, in the order the installer applies them.
     *
     * @return list<string> absolute paths
     */
    public function migrations(): array
    {
        return $this->collect('migrate_*.sql');
    }

    /** The teardown file $fileName in a module's sql directory, or null. */
    public function teardown(string $fileName): ?string
    {
        if ($fileName === '' || str_contains($fileName, '/') || str_contains($fileName, '\\')) {
            return null;
        }
        foreach ($this->moduleSqlDirs as $dir) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function collect(string $pattern): array
    {
        /** @var array<string,string> $byName */
        $byName = [];
        // Core directories are alternative locations of the SAME files (a
        // repo-root setup/ mirroring src/setup/): the first one that has a
        // file wins, as the installers have always resolved it.
        foreach ($this->coreDirs as $dir) {
            foreach ($this->glob($dir, $pattern) as $name => $path) {
                $byName[$name] ??= $path;
            }
        }
        // A module's file must not collide with core's or another module's.
        foreach ($this->moduleSqlDirs as $dir) {
            foreach ($this->glob($dir, $pattern) as $name => $path) {
                if (isset($byName[$name])) {
                    throw new \LogicException("SQL file {$name} exists twice: {$byName[$name]} and {$path}");
                }
                $byName[$name] = $path;
            }
        }
        ksort($byName, SORT_STRING);
        return array_values($byName);
    }

    /**
     * @return array<string,string> file name => path
     */
    private function glob(string $dir, string $pattern): array
    {
        $dir = rtrim($dir, '/\\');
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . $pattern) ?: [] as $path) {
            $out[basename($path)] = $path;
        }
        return $out;
    }
}
