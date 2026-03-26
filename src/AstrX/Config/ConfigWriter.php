<?php
declare(strict_types=1);

namespace AstrX\Config;

use AstrX\Config\Diagnostic\ConfigWriteDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\Result\DiagnosticLevel;

/**
 * Writes a config domain array back to its PHP config file atomically.
 *
 * Write strategy:
 *   1. Serialise using a recursive pretty-printer that emits short array syntax [].
 *   2. Write to a sibling .tmp file.
 *   3. rename() into place (atomic on POSIX).
 *
 * We do NOT use var_export() + regex because var_export emits old-style array()
 * syntax and a naive regex conversion breaks the outer closing delimiter.
 * The recursive printer below produces correct PHP in all cases.
 */
final class ConfigWriter
{
    /** @param array<string, array<string, mixed>> $config Full domain→keys array for the file */
    public function write(string $fileBaseName, array $config): Result
    {
        $path = (defined('CONFIG_DIR') ? CONFIG_DIR : '') . $fileBaseName . '.config.php';
        $php  = $this->render($config);
        $tmp  = $path . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            return Result::err(false, Diagnostics::of(new ConfigWriteDiagnostic(
                                                          'astrx.config/write_failed', DiagnosticLevel::ERROR,
                                                          $tmp, 'write_failed',
                                                      )));
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return Result::err(false, Diagnostics::of(new ConfigWriteDiagnostic(
                                                          'astrx.config/write_failed', DiagnosticLevel::ERROR,
                                                          $path, 'rename_failed',
                                                      )));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        return Result::ok(true);
    }

    /**
     * Write the main config.php file.
     * Unlike write() which appends '.config.php', this writes to 'config.php' directly.
     * @param array<string, array<string, mixed>> $config
     */
    public function writeMainConfig(array $config): Result
    {
        $path = (defined('CONFIG_DIR') ? CONFIG_DIR : '') . 'config.php';
        $php  = $this->render($config);
        $tmp  = $path . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            return Result::err(false, Diagnostics::of(new ConfigWriteDiagnostic(
                                                          'astrx.config/write_failed', DiagnosticLevel::ERROR,
                                                          $tmp, 'write_failed',
                                                      )));
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return Result::err(false, Diagnostics::of(new ConfigWriteDiagnostic(
                                                          'astrx.config/write_failed', DiagnosticLevel::ERROR,
                                                          $path, 'rename_failed',
                                                      )));
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        return Result::ok(true);
    }

    private function render(array $config): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nreturn " . $this->exportValue($config, 0) . ";\n";
    }

    /**
     * Recursively export a value as short-syntax PHP.
     * Produces correct, indented PHP for any mix of scalars, booleans, nulls, and arrays.
     */
    private function exportValue(mixed $value, int $depth): string
    {
        if ($value === null)              { return 'null'; }
        if ($value === true)              { return 'true'; }
        if ($value === false)             { return 'false'; }
        if (is_int($value))              { return (string) $value; }
        if (is_float($value))            { return rtrim(rtrim(sprintf('%F', $value), '0'), '.') ?: '0.0'; }
        if (is_string($value))           { return "'" . addcslashes($value, "'\\") . "'"; }

        if (!is_array($value) || $value === []) {
            return '[]';
        }

        $indent     = str_repeat('    ', $depth + 1);
        $closeIndent= str_repeat('    ', $depth);
        $isList     = array_is_list($value);
        $lines      = [];

        foreach ($value as $k => $v) {
            $key    = $isList ? '' : $this->exportValue($k, 0) . ' => ';
            $lines[] = $indent . $key . $this->exportValue($v, $depth + 1) . ',';
        }

        return "[\n" . implode("\n", $lines) . "\n" . $closeIndent . ']';
    }
}