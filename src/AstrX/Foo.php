<?php

declare(strict_types = 1);

namespace AstrX;

use AstrX\Config\InjectConfig;
use AstrX\Config\ConfigurableInterface;

final class Foo implements ConfigurableInterface
{
    // Property injection (no setter required) but does this work with private properties???
    #[InjectConfig('enabled')]
    private bool $enabled = false;
    #[InjectConfig('max_items')]
    private int $maxItems = 10;
    private string $mode = 'safe';

    // Setter injection example
    #[InjectConfig('mode')]
    private function setMode(string $mode)
    : void {
        $this->mode = $mode;
    }

    /**
     * If you implement ConfigurableInterface, you can validate/normalize.
     * This is the “unbreakable” place to enforce invariants.
     *
     * @param array<string, mixed> $config
     */
    public function applyConfig(array $config)
    : void {
        // Option A: allow attribute wiring first, then validate here
        // (Config::applyConfigToInstance currently calls applyConfig() and returns early,
        // so if you implement ConfigurableInterface you own everything.)
        //
        // My recommendation:
        // - either DO NOT implement ConfigurableInterface and use attributes only
        // - OR implement ConfigurableInterface and do all mapping+validation yourself.
        //
        // Here we’ll do the explicit, robust approach:

        $this->enabled = (bool)($config['enabled']??false);

        $max = $config['max_items']??10;
        if (!is_int($max) || $max < 1 || $max > 10_000) {
            $max = 10;
        }
        $this->maxItems = $max;

        $mode = $config['mode']??'safe';
        $this->mode = in_array($mode, ['safe', 'fast'], true) ? $mode : 'safe';
    }

    public function run()
    : string
    {
        if (!$this->enabled) {
            return "Foo disabled";
        }

        return "Foo running (mode={$this->mode}, maxItems={$this->maxItems})";
    }
}