<?php

declare(strict_types = 1);

namespace AstrX\Http;

final class HeaderBag
{
    /**
     * @var array<string, list<string>>
     */
    private array $headers = [];
    /**
     * @var array<string, string>
     */
    private array $originalNames = [];

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->set($name, $value);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function all()
    : array
    {
        $result = [];

        foreach ($this->headers as $normalized => $values) {
            $result[$this->originalNames[$normalized]] = $values;
        }

        return $result;
    }

    public function has(string $name)
    : bool {
        return array_key_exists($this->normalizeName($name), $this->headers);
    }

    public function get(string $name, ?string $default = null)
    : ?string {
        $values = $this->getAll($name);

        if ($values === []) {
            return $default;
        }

        return implode(', ', $values);
    }

    /**
     * @return list<string>
     */
    public function getAll(string $name)
    : array {
        return $this->headers[$this->normalizeName($name)]??[];
    }

    /**
     * @param string|array<int, string> $value
     */
    public function set(string $name, string|array $value)
    : void {
        $normalized = $this->normalizeName($name);
        $values = is_array($value) ? array_values($value) : [$value];

        $this->headers[$normalized] = array_map(
            static fn(string $item)
            : string => trim($item),
            $values,
        );

        $this->originalNames[$normalized] = $this->formatName($normalized);
    }

    public function add(string $name, string $value)
    : void {
        $normalized = $this->normalizeName($name);

        if (!isset($this->headers[$normalized])) {
            $this->headers[$normalized] = [];
            $this->originalNames[$normalized] = $this->formatName($normalized);
        }

        $this->headers[$normalized][] = trim($value);
    }

    public function remove(string $name)
    : void {
        $normalized = $this->normalizeName($name);
        unset($this->headers[$normalized], $this->originalNames[$normalized]);
    }

    public function clear()
    : void
    {
        $this->headers = [];
        $this->originalNames = [];
    }

    public function contentType()
    : ?string
    {
        $value = $this->get('Content-Type');

        if ($value === null) {
            return null;
        }

        $parts = explode(';', $value, 2);

        return trim($parts[0]);
    }

    public function authorization()
    : ?string
    {
        return $this->get('Authorization');
    }

    public function bearerToken()
    : ?string
    {
        $authorization = $this->authorization();

        if ($authorization === null) {
            return null;
        }

        if (preg_match('/^\s*Bearer\s+(.+)\s*$/i', $authorization, $matches) !==
            1) {
            return null;
        }

        return trim($matches[1]);
    }

    private function normalizeName(string $name)
    : string {
        return strtolower(trim($name));
    }

    private function formatName(string $name)
    : string {
        return implode(
            '-',
            array_map(
                static fn(string $part)
                : string => ucfirst($part),
                explode('-', $name),
            )
        );
    }
}