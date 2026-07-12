<?php

declare(strict_types=1);

namespace Fameen\Messaging\Dto;

/**
 * Aides de conversion tolérantes pour les `fromArray()` des DTO :
 * champs inconnus ignorés, champs manquants ou mal typés → valeur par défaut.
 *
 * @internal
 */
trait CastsFromArray
{
    private static function toStr(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return $default;
    }

    private static function toStrOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    private static function toInt(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function toNum(mixed $value, int|float $default = 0): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return $value + 0; // '2' → 2 (int), '1.5' → 1.5 (float)
        }

        return $default;
    }

    private static function toBool(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : $default;
    }
}
