<?php

declare(strict_types = 1);

namespace AstrX\I18n;

enum Locale: string
{
    case EN = 'en';
    case IT = 'it';

    public static function fromStringOrDefault(?string $raw, self $default)
    : self {
        if ($raw === null || $raw === '') {
            return $default;
        }
        foreach (self::cases() as $c) {
            if ($c->value === $raw) {
                return $c;
            }
        }

        return $default;
    }

    /** @param list<string> $allowed */
    public function isAllowed(array $allowed)
    : bool {
        return in_array($this->value, $allowed, true);
    }
}