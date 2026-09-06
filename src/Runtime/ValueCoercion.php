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
}
