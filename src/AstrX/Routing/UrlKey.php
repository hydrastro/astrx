<?php
declare(strict_types=1);

namespace AstrX\Routing;

final class UrlKey
{
    public function __construct(
        private readonly string $name,
        private readonly bool $i18n,
    ) {}

    public function getName(): string { return $this->name; }
    public function isI18n(): bool   { return $this->i18n; }
}
