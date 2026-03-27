<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\Result\Diagnostics;
use AstrX\Result\Result;
use AstrX\Result\DiagnosticInterface;
use AstrX\Result\DiagnosticsCollector;

abstract class AbstractController implements Controller
{
    public function __construct(protected DiagnosticsCollector $collector)
    {
    }

    /** @return Result<null> */
    final protected function ok()
    : Result
    {
        return Result::ok(null);
    }

    /** @return Result<never> */
    final protected function err(mixed $error = null, ?Diagnostics $d = null)
    : Result {
        return Result::err($error, $d);
    }

    final protected function emit(DiagnosticInterface $d)
    : void {
        $this->collector->emit($d);
    }

    /** Cast mixed→string safely for PHPStan level 9. */
    protected static function str(mixed $v, string $default = ''): string
    {
        return is_scalar($v) ? (string)$v : $default;
    }

    /** Cast mixed→int safely for PHPStan level 9. */
    protected static function int(mixed $v, int $default = 0): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int)$v : $default);
    }

    /** Cast mixed→bool safely for PHPStan level 9. */
    protected static function bool(mixed $v): bool
    {
        return (bool)$v;
    }

    /**
     * @param array<string,mixed> $arr
     */
    protected static function mStr(array $arr, string $key, string $default = ''): string
    {
        $v = $arr[$key] ?? $default;
        return is_scalar($v) ? (string)$v : $default;
    }

    /**
     * @param array<string,mixed> $arr
     */
    protected static function mInt(array $arr, string $key, int $default = 0): int
    {
        $v = $arr[$key] ?? $default;
        return is_int($v) ? $v : (is_numeric($v) ? (int)$v : $default);
    }

    /**
     * @param array<string,mixed> $arr
     */
    protected static function mBool(array $arr, string $key, bool $default = false): bool
    {
        return !empty($arr[$key]);
    }

    /**
     * @param array<string,mixed> $arr
     * @return array<string,mixed>
     */
    protected static function mArray(array $arr, string $key): array
    {
        $v = $arr[$key] ?? [];
        if (!is_array($v)) { return []; }
        /** @var array<string,mixed> $v */
        return $v;
    }
}
