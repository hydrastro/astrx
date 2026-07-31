<?php
declare(strict_types=1);

namespace AstrX\Config;

use AstrX\Config\Diagnostic\ConfigWriteDiagnostic;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\Result\DiagnosticLevel;
use function AstrX\Support\configDir;

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
    /**
     * @param array<string, array<string, mixed>> $config
     * @return Result<mixed>
     */
    public function write(string $fileBaseName, array $config): Result
    {
        $path = (configDir()) . $fileBaseName . '.config.php';

        // Merge the incoming section(s)/key(s) OVER the existing file rather than
        // replacing it wholesale. Many admin save-handlers rebuild only the keys
        // that have a form field, so a full replace silently DROPS every other key
        // in the section — e.g. the imageboard tripcode_salt/poster_id_salt
        // (→ forgeable tripcodes, predictable poster IDs) or the session
        // server_secret / regenerate_interval (→ session-fixation defence off).
        // Shallow per-section key merge: new keys win, keys the handler omitted
        // are preserved, and whole untouched sections are kept intact.
        $existing = is_file($path) ? @include $path : null;
        if (is_array($existing)) {
            /** @var array<string, mixed> $existing */
            $merged = $existing;
            foreach ($config as $section => $keys) {
                if (is_array($keys) && isset($merged[$section]) && is_array($merged[$section])) {
                    /** @var array<string, mixed> $prev */
                    $prev = $merged[$section];
                    $merged[$section] = array_merge($prev, $keys);
                } else {
                    $merged[$section] = $keys;
                }
            }
            /** @var array<string, array<string, mixed>> $merged */
            $config = $merged;
        }

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
     * @return Result<mixed>
     */
    public function writeMainConfig(array $config): Result
    {
        $path = (configDir()) . 'config.php';
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

    /** @param array<string,mixed> $config */
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
        if (is_float($value)) {
            // Keep a float a float on round-trip: an integer-valued float (1.0)
            // must NOT serialize to "1" (which reloads as int and breaks is_float/
            // === checks). Ensure a decimal point survives (F-22).
            $s = rtrim(rtrim(sprintf('%F', $value), '0'), '.');
            if ($s === '' || $s === '-') { $s = '0'; }
            return str_contains($s, '.') ? $s : $s . '.0';
        }
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
