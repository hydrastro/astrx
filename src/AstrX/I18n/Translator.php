<?php
declare(strict_types=1);

namespace AstrX\I18n;

use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkInterface;
use AstrX\I18n\Diagnostic\InvalidLanguageArrayDiagnostic;
use AstrX\I18n\Diagnostic\InvalidLanguageFileDiagnostic;
use AstrX\I18n\Diagnostic\MissingTranslationDiagnostic;

final class Translator
{
    // Diagnostic policy — the emitting class always owns the ID and level.
    public const string ID_MISSING_TRANSLATION = 'astrx.i18n/missing_translation';
    public const DiagnosticLevel LVL_MISSING_TRANSLATION = DiagnosticLevel::NOTICE;

    public const string ID_INVALID_LANGUAGE_FILE = 'astrx.i18n/invalid_language_file';
    public const DiagnosticLevel LVL_INVALID_LANGUAGE_FILE = DiagnosticLevel::ERROR;

    public const string ID_INVALID_LANGUAGE_ARRAY = 'astrx.i18n/invalid_language_array';
    public const DiagnosticLevel LVL_INVALID_LANGUAGE_ARRAY = DiagnosticLevel::WARNING;

    private string $locale;

    /** @var array<string, string|callable(array, Translator): string> */
    private array $catalog = [];

    private ?DiagnosticSinkInterface $sink = null;

    public function __construct(?DiagnosticSinkInterface $sink = null)
    {
        $this->locale = 'en';
        $this->sink   = $sink;
    }

    public function setDiagnosticSink(?DiagnosticSinkInterface $sink): void
    {
        $this->sink = $sink;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function loadDomain(string $langDir, string $domain): void
    {
        $file = rtrim($langDir, '/\\') . DIRECTORY_SEPARATOR
                . $this->locale . DIRECTORY_SEPARATOR
                . $domain . '.php';

        $this->loadFile($file);
    }

    public function loadFile(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $data = require $file;

        if (!is_array($data)) {
            $this->emit(new InvalidLanguageFileDiagnostic(
                            self::ID_INVALID_LANGUAGE_FILE,
                            self::LVL_INVALID_LANGUAGE_FILE,
                            $file
                        ));
            return;
        }

        // Load all valid entries; skip and report bad ones individually so that
        // valid translations before a bad entry are not silently discarded.
        $clean = [];

        foreach ($data as $k => $v) {
            if (!is_string($k) || !(is_string($v) || is_callable($v))) {
                $this->emit(new InvalidLanguageArrayDiagnostic(
                                self::ID_INVALID_LANGUAGE_ARRAY,
                                self::LVL_INVALID_LANGUAGE_ARRAY,
                                is_string($k) ? $k : '(non-string key)',
                                $file,
                            ));
                continue;
            }
            $clean[$k] = $v;
        }

        /** @var array<string, string|callable(array, Translator): string> $clean */
        $this->catalog = array_merge($this->catalog, $clean);
    }

    /**
     * @param array<string, scalar|\Stringable|null> $vars
     */
    public function t(string $key, array $vars = [], ?string $fallback = null): string
    {
        if (!array_key_exists($key, $this->catalog)) {
            echo "$key <br>";
            $this->emit(new MissingTranslationDiagnostic(
                            self::ID_MISSING_TRANSLATION,
                            self::LVL_MISSING_TRANSLATION,
                            $this->locale,
                            $key
                        ));
            return $fallback ?? $key;
        }

        $entry = $this->catalog[$key];

        if (is_string($entry)) {
            return $this->interpolate($entry, $vars);
        }

        $out = $entry($vars, $this);
        return $this->interpolate($out, $vars);
    }

    /**
     * @param array<string, scalar|\Stringable|null> $vars
     */
    private function interpolate(string $template, array $vars): string
    {
        if ($vars === []) {
            return $template;
        }

        $replace = [];
        foreach ($vars as $k => $v) {
            if (!is_string($k)) {
                continue;
            }

            $replace['{' . $k . '}'] = match (true) {
                $v === null           => '',
                is_scalar($v)         => (string) $v,
                $v instanceof \Stringable => (string) $v,
                default               => '',
            };
        }

        return strtr($template, $replace);
    }

    private function emit(\AstrX\Result\DiagnosticInterface $d): void
    {
        $this->sink?->emit($d);
    }
}