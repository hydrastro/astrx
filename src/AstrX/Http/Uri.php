<?php

declare(strict_types = 1);

namespace AstrX\Http;

final class Uri
{
    public function __construct(
        private readonly string $scheme,
        private readonly string $host,
        private readonly ?int $port,
        private readonly string $path,
        private readonly string $query,
        private readonly string $fragment,
    ) {
    }

    public static function fromString(string $uri)
    : self {
        $parts = parse_url($uri);

        return new self(
            scheme:   isset($parts['scheme']) ? (string)$parts['scheme'] : '',
            host:     isset($parts['host']) ? (string)$parts['host'] : '',
            port:     isset($parts['port']) ? (int)$parts['port'] : null,
            path:     isset($parts['path']) ? (string)$parts['path'] : '/',
            query:    isset($parts['query']) ? (string)$parts['query'] : '',
            fragment: isset($parts['fragment']) ? (string)$parts['fragment'] :
                          '',
        );
    }

    public function scheme()
    : string
    {
        return $this->scheme;
    }

    public function host()
    : string
    {
        return $this->host;
    }

    public function port()
    : ?int
    {
        return $this->port;
    }

    public function path()
    : string
    {
        return $this->path;
    }

    public function query()
    : string
    {
        return $this->query;
    }

    public function fragment()
    : string
    {
        return $this->fragment;
    }

    public function withPath(string $path)
    : self {
        return new self(
            scheme:   $this->scheme,
            host:     $this->host,
            port:     $this->port,
            path:     $path,
            query:    $this->query,
            fragment: $this->fragment,
        );
    }

    public function __toString()
    : string
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . '://';
        }

        $uri .= $this->host;

        if ($this->port !== null) {
            $uri .= ':' . $this->port;
        }

        $uri .= $this->path !== '' ? $this->path : '/';

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }
}