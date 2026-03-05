<?php

namespace AstrX\Routing;

class KeyMapping
{
    public function __construct(
        private UrlKey $urlKey,
        private string $value
    ) {}

    public static function new(
         UrlKey $urlKey,
         string $value
    ) {
        return new self($urlKey, $value);
    }

    /**
     * @return \AstrX\Routing\UrlKey
     */
    public function getUrlKey()
    : UrlKey
    {
        return $this->urlKey;
    }

    /**
     * @return string
     */
    public function getValue()
    : string
    {
        return $this->value;
    }
}