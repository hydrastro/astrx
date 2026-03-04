<?php
declare(strict_types=1);

namespace AstrX\I18n;

use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkInterface;
use AstrX\I18n\Diagnostic\MissingTranslationDiagnostic;
use AstrX\I18n\Diagnostic\InvalidLanguageFileDiagnostic;

final class Translator
{
    public const ID_MISSING_TRANSLATION = 'astrx.i18n/missing_translation';
    public const LVL_MISSING_TRANSLATION = DiagnosticLevel::NOTICE;

    public const ID_INVALID_LANGUAGE_FILE = 'astrx.i18n/invalid_language_file';
    public const LVL_INVALID_LANGUAGE_FILE = DiagnosticLevel::ERROR;

    private string $locale;

    /** @var array<string, string|callable(array, Translator): string> */
    private array $catalog = [];

    private ?DiagnosticSinkInterface $sink = null;

    public function __construct(string $locale = 'en', ?DiagnosticSinkInterface $sink = null)
    {
        $this->locale = $locale;
        $this->sink = $sink;
    }

    public function setDiagnosticSink(?DiagnosticSinkInterface $sink): void
    {
        $this->sink = $sink;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function loadDomain(string $langDir, string $domain): void
    {
        // todo: check for annotation / interface and do deterministic loading
        $file = rtrim($langDir, '/\\') . DIRECTORY_SEPARATOR
                . $this->locale . DIRECTORY_SEPARATOR
                . $domain . '.' . $this->locale . '.php';

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

        foreach ($data as $k => $v) {
            if (!is_string($k) || !(is_string($v) || is_callable($v))) {
                $this->emit(new InvalidLanguageFileDiagnostic(
                                self::ID_INVALID_LANGUAGE_FILE,
                                self::LVL_INVALID_LANGUAGE_FILE,
                                $file
                            ));
                return;
            }
        }

        /** @var array<string, string|callable(array, Translator): string> $data */
        $this->catalog = array_merge($this->catalog, $data);
    }

    /**
     * @param array<string, scalar|\Stringable|null> $vars
     */
    public function t(string $key, array $vars = [], ?string $fallback = null): string
    {
        if (!array_key_exists($key, $this->catalog)) {
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
        if ($vars === []) return $template;

        $replace = [];
        foreach ($vars as $k => $v) {
            if (!is_string($k)) continue;

            $replace['{' . $k . '}'] = match (true) {
                $v === null => '',
                is_scalar($v) => (string)$v,
                $v instanceof \Stringable => (string)$v,
                default => '',
            };
        }

        return strtr($template, $replace);
    }

    private function emit(\AstrX\Result\DiagnosticInterface $d): void
    {
        $this->sink?->emit($d);
    }
}