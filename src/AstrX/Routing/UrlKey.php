<?php

namespace AstrX\Routing;

class UrlKey {
    public function __construct(
        private string $name,
        private bool $i18n
    ) {}

    /**
     * @return string
     */
    public function getName()
    : string
    {
        return $this->name;
    }

    /**
     * @return bool
     */
    public function isI18n()
    : bool
    {
        return $this->i18n;
    }
}
