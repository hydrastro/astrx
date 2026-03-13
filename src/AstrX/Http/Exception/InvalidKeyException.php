<?php

declare(strict_types = 1);

namespace AstrX\Http\Exception;

/**
 * Thrown when a bag (ParameterBag, FileBag) receives a non-string key.
 * Carries the offending key as a typed field so the catch site can
 * render a message in whatever language or format it chooses.
 */
final class InvalidKeyException extends \InvalidArgumentException
{
    public function __construct(
        private readonly mixed $key,
        private readonly string $bagClass,
    ) {
        // Parent message is intentionally minimal — callers read the fields.
        parent::__construct();
    }

    /** The key that was rejected. */
    public function key()
    : mixed
    {
        return $this->key;
    }

    /** Fully-qualified class name of the bag that threw. */
    public function bagClass()
    : string
    {
        return $this->bagClass;
    }
}