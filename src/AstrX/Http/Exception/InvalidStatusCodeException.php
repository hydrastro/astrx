<?php
declare(strict_types=1);

namespace AstrX\Http\Exception;

/**
 * Thrown when an integer outside the valid HTTP status range (100–599)
 * is passed to Response.
 * Carries the offending value as a typed property so the catch site can
 * render a message in whatever language or format it chooses.
 *
 * Note: the method is named statusCode() (not code()) to avoid conflicting
 * with the inherited Exception::getCode() whose return type broadened to
 * int|string in PHP 8.0 and causes a fatal type error under PHP 8.4 strict
 * mode if a subclass declares `code(): int`.
 */
final class InvalidStatusCodeException extends \InvalidArgumentException
{
    public function __construct(
        private readonly int $statusCode,
    ) {
        parent::__construct();
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}