<?php
declare(strict_types=1);

namespace AstrX\Http;

use AstrX\Http\Diagnostic\UnknownMethodDiagnostic;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;

enum RequestMethod: string
{
    case Get     = 'GET';
    case Post    = 'POST';
    case Put     = 'PUT';
    case Patch   = 'PATCH';
    case Delete  = 'DELETE';
    case Head    = 'HEAD';
    case Options = 'OPTIONS';
    case Trace   = 'TRACE';
    case Connect = 'CONNECT';

    public const string ID_UNKNOWN_METHOD = 'astrx.http/unknown_method';
    public const DiagnosticLevel LVL_UNKNOWN_METHOD = DiagnosticLevel::WARNING;

    /** @return Result<self> */
    public static function fromString(string $method): Result
    {
        $case = self::tryFrom(strtoupper($method));

        if ($case === null) {
            return Result::err(null, Diagnostics::of(
                new UnknownMethodDiagnostic(
                    self::ID_UNKNOWN_METHOD,
                    self::LVL_UNKNOWN_METHOD,
                    $method,
                )
            ));
        }

        return Result::ok($case);
    }

    public function isSafe(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Options, self::Trace => true,
            default => false,
        };
    }

    public function isIdempotent(): bool
    {
        return match ($this) {
            self::Get, self::Head, self::Put, self::Delete, self::Options, self::Trace => true,
            default => false,
        };
    }
}