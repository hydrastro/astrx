<?php
declare(strict_types=1);

namespace AstrX\Routing;

final class KeyMapping
{
    public function __construct(
        private readonly UrlKey $urlKey,
        private readonly string $value,
    ) {}

    public static function new(UrlKey $urlKey, string $value): self
    {
        return new self($urlKey, $value);
    }

    public function getUrlKey(): UrlKey { return $this->urlKey; }
    public function getValue(): string  { return $this->value; }
}
