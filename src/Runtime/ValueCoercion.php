<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Traversable;

final class ValueCoercion
{
    public static function toString(mixed $value): string
    {
        if ($value instanceof VoidValue || $value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode('', array_map(self::toString(...), $value));
        }

        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : '';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        return '';
    }

    public static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return 0;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function toArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function toScopeFrame(mixed $item): ?array
    {
        $iterable = self::toArray($item);
        if ($iterable !== null) {
            return self::stringKeys($iterable);
        }

        if (is_object($item)) {
            return self::stringKeys(get_object_vars($item));
        }

        return null;
    }

    public static function add(int|float $left, int|float $right): int|float
    {
        return is_int($left) && is_int($right) ? $left + $right : (float) $left + (float) $right;
    }

    public static function subtract(int|float $left, int|float $right): int|float
    {
        return is_int($left) && is_int($right) ? $left - $right : (float) $left - (float) $right;
    }

    public static function multiply(int|float $left, int|float $right): int|float
    {
        return is_int($left) && is_int($right) ? $left * $right : (float) $left * (float) $right;
    }

    public static function divide(int|float $left, int|float $right): int|float
    {
        return is_int($left) && is_int($right) ? $left / $right : (float) $left / (float) $right;
    }

    public static function modulo(int|float $left, int|float $right): int|float
    {
        return is_int($left) && is_int($right) ? $left % $right : fmod($left, $right);
    }

    public static function power(int|float $left, int|float $right): int|float
    {
        return is_int($left) && is_int($right) ? $left ** $right : floatval($left) ** floatval($right);
    }

    public static function toNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return 0;
    }

    public static function compare(mixed $left, mixed $right): int
    {
        return self::sortableValue($left) <=> self::sortableValue($right);
    }

    /**
     * @param  array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    public static function stringKeys(array $data): array
    {
        /** @var array<string, mixed> $stringKeyed */
        $stringKeyed = array_filter($data, is_string(...), ARRAY_FILTER_USE_KEY);

        return $stringKeyed;
    }

    private static function sortableValue(mixed $value): int|float|string
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return self::toString($value);
    }
}
