<?php

declare(strict_types = 1);

namespace AstrX\Http;

enum RequestMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Head = 'HEAD';
    case Options = 'OPTIONS';
    case Trace = 'TRACE';
    case Connect = 'CONNECT';

    public static function fromString(string $method)
    : self {
        return self::from(strtoupper($method));
    }

    public function isSafe()
    : bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options, self::Trace => true,
            default => false,
        };
    }

    public function isIdempotent()
    : bool
    {
        return match ($this) {
            self::Get, self::Head, self::Put, self::Delete, self::Options, self::Trace => true,
            default => false,
        };
    }
}