<?php
declare(strict_types=1);

namespace AstrX\Routing;

/**
 * Simple rewrite-mode URL segment stack.
 */
final class UrlStack
{
    /** @var list<string> */
    private array $segments;
    private int $i = 0;

    /** @param list<string> $segments */
    private function __construct(array $segments)
    {
        $this->segments = array_values($segments);
    }

    public static function fromRequest(string $requestUri, string $basePath = '/'): self
    {
        $path = explode('?', $requestUri, 2)[0];

        $parts = array_values(array_filter(
                                  array_map('rawurldecode', explode('/', $path)),
                                  static fn($s) => $s !== ''
                              ));

        // remove basePath prefix segments
        $bp = array_values(array_filter(
                               explode('/', trim($basePath, '/')),
                               static fn($s) => $s !== ''
                           ));

        for ($j = 0; $j < count($bp) && isset($parts[$j]); $j++) {
            if ($parts[$j] === $bp[$j]) {
                unset($parts[$j]);
            } else {
                break;
            }
        }

        $parts = array_values($parts);

        return new self($parts);
    }

    public function peek(): ?string
    {
        return $this->segments[$this->i] ?? null;
    }

    public function pop(): ?string
    {
        if (!isset($this->segments[$this->i])) {
            return null;
        }
        return $this->segments[$this->i++];
    }

    /** @return list<string> */
    public function remaining(): array
    {
        return array_slice($this->segments, $this->i);
    }
}