<?php

declare(strict_types = 1);

namespace AstrX\Http;

use InvalidArgumentException;
// TODO: customize throw
final class ParameterBag
{
    public function __construct(
        private array $items = [],
    ) {
    }

    public function all()
    : array
    {
        return $this->items;
    }

    public function keys()
    : array
    {
        return array_keys($this->items);
    }

    public function has(string $key)
    : bool {
        return array_key_exists($key, $this->items);
    }

    public function get(string $key, mixed $default = null)
    : mixed {
        return array_key_exists($key, $this->items) ? $this->items[$key] :
            $default;
    }

    public function set(string $key, mixed $value)
    : void {
        $this->items[$key] = $value;
    }

    public function add(array $items)
    : void {
        foreach ($items as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(
                    'Parameter bag keys must be strings.'
                );
            }

            $this->items[$key] = $value;
        }
    }

    public function replace(array $items)
    : void {
        foreach (array_keys($items) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(
                    'Parameter bag keys must be strings.'
                );
            }
        }

        $this->items = $items;
    }

    public function remove(string $key)
    : void {
        unset($this->items[$key]);
    }

    public function clear()
    : void
    {
        $this->items = [];
    }

    public function getString(string $key, ?string $default = null)
    : ?string {
        $value = $this->get($key, $default);

        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Value for key "%s" is not scalar and cannot be converted to string.',
                    $key,
                )
            );
        }

        return (string)$value;
    }

    public function getInt(string $key, ?int $default = null)
    : ?int {
        $value = $this->get($key, $default);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int)$value;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Value for key "%s" cannot be converted to int.',
                $key,
            )
        );
    }

    public function getBool(string $key, ?bool $default = null)
    : ?bool {
        $value = $this->get($key, $default);

        if ($value === null || is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                '1', 'true', 'on', 'yes' => true,
                '0', 'false', 'off', 'no' => false,
                default => throw new InvalidArgumentException(
                    sprintf(
                        'Value for key "%s" cannot be converted to bool.',
                        $key,
                    )
                ),
            };
        }

        if (is_int($value)) {
            return match ($value) {
                1 => true,
                0 => false,
                default => throw new InvalidArgumentException(
                    sprintf(
                        'Value for key "%s" cannot be converted to bool.',
                        $key,
                    )
                ),
            };
        }

        throw new InvalidArgumentException(
            sprintf(
                'Value for key "%s" cannot be converted to bool.',
                $key,
            )
        );
    }
}
