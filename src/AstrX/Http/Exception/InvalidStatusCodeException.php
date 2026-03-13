<?php

declare(strict_types = 1);

namespace AstrX\Http\Exception;

/**
 * Thrown when an integer outside the valid HTTP status range (100–599)
 * is passed to Response.
 * Carries the offending value as a typed field so the catch site can
 * render a message in whatever language or format it chooses.
 */
final class InvalidStatusCodeException extends \InvalidArgumentException
{
    public function __construct(
        private readonly int $code,
    ) {
        parent::__construct();
    }

    /** The invalid status code that was rejected. */
    public function code()
    : int
    {
        return $this->code;
    }
}