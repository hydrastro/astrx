<?php
declare(strict_types=1);

namespace AstrX\Config;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class InjectConfig
{
    public function __construct(
        public readonly string $key
    ) {}
}