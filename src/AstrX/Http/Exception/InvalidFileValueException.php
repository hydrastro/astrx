<?php

declare(strict_types = 1);

namespace AstrX\Http\Exception;

/**
 * Thrown when FileBag receives a value that is neither an UploadedFile nor an array.
 * Carries the key and the actual value type so the catch site can
 * render a message in whatever language or format it chooses.
 */
final class InvalidFileValueException extends \InvalidArgumentException
{
    public function __construct(
        private readonly string $key,
        private readonly string $actualType,
    ) {
        parent::__construct();
    }

    /** The key whose value was rejected. */
    public function key()
    : string
    {
        return $this->key;
    }

    /** The actual PHP type of the rejected value (from get_debug_type()). */
    public function actualType()
    : string
    {
        return $this->actualType;
    }
}