<?php

declare(strict_types=1);

namespace AstrX\Http;

use AstrX\Http\Diagnostic\InvalidParameterTypeDiagnostic;
use AstrX\Http\Exception\InvalidKeyException;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\Diagnostics;
use AstrX\Result\Result;

final class ParameterBag
{
    public const string ID_INVALID_TYPE = 'astrx.http/invalid_parameter_type';
    public const DiagnosticLevel LVL_INVALID_TYPE = DiagnosticLevel::WARNING;

    public function __construct(
        private array $items = [],
    ) {}

    public function all(): array  { return $this->items; }
    public function keys(): array { return array_keys($this->items); }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->items[$key] = $value;
    }

    public function add(array $items): void
    {
        foreach ($items as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidKeyException($key, self::class);
            }
            $this->items[$key] = $value;
        }
    }

    public function replace(array $items): void
    {
        foreach (array_keys($items) as $key) {
            if (!is_string($key)) {
                throw new InvalidKeyException($key, self::class);
            }
        }
        $this->items = $items;
    }

    public function remove(string $key): void { unset($this->items[$key]); }
    public function clear(): void             { $this->items = []; }

    // -------------------------------------------------------------------------
    // Typed getters — return Result so callers can handle conversion failures
    // without relying on exceptions for control flow.
    //
    // Result::ok(null)  → key not found (or value is null)
    // Result::ok($v)    → key found and value successfully converted
    // Result::err(null) → key found but value cannot be converted to the target type
    // -------------------------------------------------------------------------

    /** @return Result<string|null> */
    public function getString(string $key, ?string $default = null): Result
    {
        $value = $this->get($key, $default);

        if ($value === null) {
            return Result::ok(null);
        }

        if (!is_scalar($value)) {
            return Result::err(null, Diagnostics::of(
                new InvalidParameterTypeDiagnostic(self::ID_INVALID_TYPE, self::LVL_INVALID_TYPE, $key, 'string')
            ));
        }

        return Result::ok((string) $value);
    }

    /** @return Result<int|null> */
    public function getInt(string $key, ?int $default = null): Result
    {
        $value = $this->get($key, $default);

        if ($value === null) {
            return Result::ok(null);
        }

        if (is_int($value)) {
            return Result::ok($value);
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return Result::ok((int) $value);
        }

        return Result::err(null, Diagnostics::of(
            new InvalidParameterTypeDiagnostic(self::ID_INVALID_TYPE, self::LVL_INVALID_TYPE, $key, 'int')
        ));
    }

    /** @return Result<bool|null> */
    public function getBool(string $key, ?bool $default = null): Result
    {
        $value = $this->get($key, $default);

        if ($value === null || is_bool($value)) {
            return Result::ok($value);
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                '1', 'true', 'on', 'yes'  => Result::ok(true),
                '0', 'false', 'off', 'no' => Result::ok(false),
                default => Result::err(null, Diagnostics::of(
                    new InvalidParameterTypeDiagnostic(self::ID_INVALID_TYPE, self::LVL_INVALID_TYPE, $key, 'bool')
                )),
            };
        }

        if (is_int($value)) {
            return match ($value) {
                1 => Result::ok(true),
                0 => Result::ok(false),
                default => Result::err(null, Diagnostics::of(
                    new InvalidParameterTypeDiagnostic(self::ID_INVALID_TYPE, self::LVL_INVALID_TYPE, $key, 'bool')
                )),
            };
        }

        return Result::err(null, Diagnostics::of(
            new InvalidParameterTypeDiagnostic(self::ID_INVALID_TYPE, self::LVL_INVALID_TYPE, $key, 'bool')
        ));
    }
}