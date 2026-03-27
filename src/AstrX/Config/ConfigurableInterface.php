<?php

declare(strict_types = 1);

namespace AstrX\Config;

interface ConfigurableInterface
{
    /** @param array<string, mixed> $config */
    public function applyConfig(array $config)
    : void;
}