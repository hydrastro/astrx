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
 *
 * Section routing: a section is written to the file that CURRENTLY DECLARES it,
 * not to the file name the caller passed. Section and file name differ for 25 of
 * the 38 declared sections (section `ImapClient` lives in `Mail.config.php`), and
 * that convention used to be re-derived by hand at every save handler. The
 * webmail editor got it wrong and persisted `ImapClient` to `Imap.config.php` —
 * a file nothing loads — so every IMAP setting, including the SOCKS5 host/port
 * that routes IMAP through Tor, was write-only. Routing through
 * {@see ConfigDomainResolver} makes reader and writer read the same layout.
 */
final class ConfigWriter
{
    private ?ConfigDomainResolver $resolver;

    public function __construct(?ConfigDomainResolver $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * @param array<string, array<string, mixed>> $config
     * @return Result<mixed>
     */
    public function write(string $fileBaseName, array $config): Result
    {
        $diagnostics = Diagnostics::empty();

        // Group each section under the file that actually declares it. A section
        // no file declares is new and stays with the caller's chosen file.
        /** @var array<string, array<string, array<string, mixed>>> $byFile */
        $byFile = [];
        foreach ($config as $section => $keys) {
            $target = $this->resolver()->fileForSection($section) ?? $fileBaseName;
            if ($target !== $fileBaseName) {
                $diagnostics = $diagnostics->with(new ConfigWriteDiagnostic(
                    'astrx.config/write_retargeted',
                    DiagnosticLevel::WARNING,
                    $this->pathFor($target),
                    $section . ' requested in ' . $fileBaseName,
                ));
            }
            $byFile[$target][$section] = $keys;
        }

        foreach ($byFile as $target => $sections) {
            $result = $this->writeFile($this->pathFor($target), $sections);
            if (!$result->isOk()) {
                return $result->withDiagnostics($diagnostics);
            }
            $diagnostics = $diagnostics->concat($result->diagnostics());
        }

        return Result::ok(true, $diagnostics);
    }

    /**
     * Write the main config.php file.
     * Unlike write() which appends '.config.php', this writes to 'config.php' directly.
     * @param array<string, array<string, mixed>> $config
     * @return Result<mixed>
     */
    public function writeMainConfig(array $config): Result
    {
        return $this->writeFile((configDir()) . 'config.php', $config, merge: false);
    }

    /**
     * Serialise $config to $path via tmp-file + rename.
     *
     * @param array<string, array<string, mixed>> $config
     * @param bool $merge Merge over the file's current contents (per-section,
     *                    per-key) instead of replacing it wholesale.
     * @return Result<mixed>
     */
    private function writeFile(string $path, array $config, bool $merge = true): Result
    {
        if ($merge) {
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
                    if (isset($merged[$section]) && is_array($merged[$section])) {
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

    /** Absolute path of a config file base name; 'config' is the main file. */
    private function pathFor(string $fileBaseName): string
    {
        return $fileBaseName === 'config'
            ? (configDir()) . 'config.php'
            : (configDir()) . $fileBaseName . '.config.php';
    }

    private function resolver(): ConfigDomainResolver
    {
        return $this->resolver ??= new ConfigDomainResolver();
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
