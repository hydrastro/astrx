<?php

declare(strict_types = 1);

namespace AstrX\Routing;

final class RouteStack
{
    /** @var list<string> */
    private array $segments;
    private int $cursor = 0;

    /** @param list<string> $segments */
    public function __construct(array $segments)
    {
        $this->segments = array_values($segments);
    }

    public static function fromRequestUri(
        string $requestUri,
        string $basePath = '/'
    )
    : self {
        $path = explode('?', $requestUri, 2)[0];

        $parts = array_values(
            array_filter(
                array_map('rawurldecode', explode('/', $path)),
                static fn($s) => $s !== ''
            )
        );

        // remove basePath prefix segments
        $bp = array_values(
            array_filter(
                explode('/', trim($basePath, '/')),
                static fn($s) => $s !== ''
            )
        );
        for ($i = 0; $i < count($bp) && isset($parts[$i]); $i++) {
            if ($parts[$i] === $bp[$i]) {
                unset($parts[$i]);
            } else {
                break;
            }
        }
        $parts = array_values($parts);

        return new self($parts);
    }

    public function peek()
    : ?string
    {
        return $this->segments[$this->cursor]??null;
    }

    public function take()
    : ?string
    {
        if (!isset($this->segments[$this->cursor])) {
            return null;
        }

        return $this->segments[$this->cursor++];
    }

    /** @return list<?string> */
    public function takeN(int $n)
    : array {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->take();
        }

        return $out;
    }

    public function cursor()
    : int
    {
        return $this->cursor;
    }

    public function setCursor(int $cursor)
    : void {
        if ($cursor < 0) {
            $cursor = 0;
        }
        if ($cursor > count($this->segments)) {
            $cursor = count($this->segments);
        }
        $this->cursor = $cursor;
    }

    /** @return list<string> */
    public function remaining()
    : array
    {
        return array_slice($this->segments, $this->cursor);
    }
}