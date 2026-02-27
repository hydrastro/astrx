<?php

declare(strict_types = 1);

namespace AstrX\Routing;

final class RequestBag
{
    public function __construct(private RouteState $state)
    {
    }

    public function get(string $key, ?string $default = null)
    : ?string {
        return $this->state->get($key, $default);
    }

    public function set(string $key, ?string $value)
    : void {
        $this->state->set($key, $value);
    }

    /** @return array<string,string|null> */
    public function all()
    : array
    {
        return $this->state->all();
    }
}