<?php

declare(strict_types = 1);

namespace AstrX\Routing;

/**
 * Loads canonical->external query key maps from:
 * - resources/lang/<locale>/routing.php               (global head keys)
 * - resources/lang/<locale>/<Domain>.routing.php      (controller/module keys)
 */
final class RoutingAliasLoader
{
    public function __construct(private string $langDir)
    {
    }

    /**
     * @return array<string,string> canonical => external
     */
    public function loadGlobal(string $locale)
    : array {
        $file = rtrim($this->langDir, '/\\') .
                DIRECTORY_SEPARATOR .
                $locale .
                DIRECTORY_SEPARATOR .
                'routing.php';

        return $this->loadFile($file);
    }

    /**
     * @return array<string,string> canonical => external
     */
    public function loadDomain(string $locale, string $domain)
    : array {
        $file = rtrim($this->langDir, '/\\') .
                DIRECTORY_SEPARATOR .
                $locale .
                DIRECTORY_SEPARATOR .
                $domain .
                '.routing.php';

        return $this->loadFile($file);
    }

    /**
     * @return array<string,string>
     */
    private function loadFile(string $file)
    : array {
        if (!is_file($file)) {
            return [];
        }

        $data = require $file;
        if (!is_array($data)) {
            return [];
        }

        foreach ($data as $k => $v) {
            if (!is_string($k) || !is_string($v)) {
                return [];
            }
        }

        /** @var array<string,string> $data */
        return $data;
    }
}