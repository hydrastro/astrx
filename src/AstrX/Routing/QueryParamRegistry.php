<?php

declare(strict_types = 1);

namespace AstrX\Routing;

final class QueryParamRegistry
{
    /**
     * locale => domain => [ canonicalKey => externalKey ]
     * @var array<string, array<string, array<string,string>>>
     */
    private array $map = [];

    /**
     * Load mapping file (optional).
     * File MUST return array<string,string>.
     */
    public function loadFile(string $locale, string $domain, string $file)
    : void {
        if (!is_file($file)) {
            return;
        }

        $data = require $file;
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $k => $v) {
            if (!is_string($k) || !is_string($v)) {
                return;
            }
        }

        /** @var array<string,string> $data */
        $this->map[$locale][$domain] = array_merge(
            $this->map[$locale][$domain]??[],
            $data
        );
    }

    /** @return array<string,string> */
    public function mapFor(string $locale, string $domain)
    : array {
        return $this->map[$locale][$domain]??[];
    }

    /**
     * Resolve canonical -> external, searching domains in priority order.
     *
     * @param list<string> $domains
     */
    public function externalKey(
        string $locale,
        array $domains,
        string $canonical
    )
    : ?string {
        foreach ($domains as $d) {
            $m = $this->mapFor($locale, $d);
            if (isset($m[$canonical])) {
                return $m[$canonical];
            }
        }

        return null;
    }

    /**
     * Resolve external -> canonical, searching domains in priority order.
     *
     * @param list<string> $domains
     */
    public function canonicalKey(
        string $locale,
        array $domains,
        string $external
    )
    : ?string {
        foreach ($domains as $d) {
            $m = $this->mapFor($locale, $d);
            // invert on demand (small maps)
            foreach ($m as $canon => $ext) {
                if ($ext === $external) {
                    return $canon;
                }
            }
        }

        return null;
    }
}