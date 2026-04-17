<?php

declare(strict_types=1);

namespace Bugo\Antlers\Modifiers;

use ArrayAccess;
use Bugo\Antlers\Runtime\ValueResult;
use Bugo\Antlers\Support\MarkdownRenderer;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\UnicodeString;
use Traversable;

/**
 * Registers all built-in Antlers modifiers.
 */
final class CoreModifiers
{
    public static function register(ModifierRegistry $registry): void
    {
        $registry->register('upper', static fn(mixed $v): string => self::unicode($v)->upper()->toString());

        $registry->register('lower', static fn(mixed $v): string => self::unicode($v)->lower()->toString());

        $registry->register('ucfirst', static fn(mixed $v): string => self::ucfirst(self::string($v)));

        $registry->register('lcfirst', static fn(mixed $v): string => self::lcfirst(self::string($v)));

        $registry->register('title', static fn(mixed $v): string
            => self::unicode($v)->title(true)->toString());

        $registry->register('trim', static fn(mixed $v, array $p): string
            => trim(self::string($v), self::string($p[0] ?? " \t\n\r\0\x0B")));

        $registry->register('reverse', static function (mixed $v): array|string {
            $items = self::iterableToArray($v);
            if ($items !== null) {
                return array_reverse($items);
            }

            return self::unicode($v)->reverse()->toString();
        });

        $registry->register('length', static function (mixed $v): int {
            $items = self::iterableToArray($v);
            if ($items !== null) {
                return count($items);
            }

            return self::unicode($v)->length();
        });

        $registry->register('count', static function (mixed $v): int {
            $items = self::iterableToArray($v);
            if ($items !== null) {
                return count($items);
            }

            if (is_string($v)) {
                return self::unicode($v)->length();
            }

            return 0;
        });

        $registry->register('word_count', static fn(mixed $v): int => self::wordCount(self::string($v)));

        $registry->register('slugify', static function (mixed $v, array $p): string {
            $sep = self::string($p[0] ?? '-');

            return self::slugger()
                ->slug(self::string($v), $sep)
                ->lower()
                ->toString();
        });

        $registry->register('snake', static fn(mixed $v): string => self::unicode($v)->snake()->toString());

        $registry->register('studly', static fn(mixed $v): string => self::unicode($v)->pascal()->toString());

        $registry->register('kebab', static fn(mixed $v): string => self::unicode($v)->kebab()->toString());

        $registry->register('truncate', static function (mixed $v, array $p): string {
            $limit  = self::int($p[0] ?? 100);
            $append = self::string($p[1] ?? '...');
            $str    = self::string($v);

            if (self::unicode($str)->length() <= $limit) {
                return $str;
            }

            return self::unicode($str)->slice(0, $limit)->toString() . $append;
        });

        $registry->register('limit', static function (mixed $v, array $p): array|string {
            $limit = self::int($p[0] ?? 100);

            $items = self::iterableToArray($v);
            if ($items !== null) {
                return array_slice($items, 0, $limit);
            }

            return self::unicode($v)->slice(0, $limit)->toString();
        });

        $registry->register('replace', static fn(mixed $v, array $p): string
            => str_replace(self::string($p[0] ?? ''), self::string($p[1] ?? ''), self::string($v)));

        $registry->register('regex_replace', static function (mixed $v, array $p): ?string {
            $pattern = self::string($p[0] ?? '');
            if ($pattern === '') {
                return self::string($v);
            }

            return preg_replace($pattern, self::string($p[1] ?? ''), self::string($v));
        });

        $registry->register('nl2br', static fn(mixed $v): string => nl2br(self::string($v)));

        $registry->register('strip_tags', static fn(mixed $v, array $p): string
            => strip_tags(self::string($v), isset($p[0]) ? self::string($p[0]) : null));

        $registry->register('entities', static fn(mixed $v): string
            => htmlspecialchars(self::string($v), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $registry->register('sanitize', static fn(mixed $v): string
            => htmlspecialchars(self::string($v), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $registry->register('decode', static fn(mixed $v): string
            => htmlspecialchars_decode(self::string($v), ENT_QUOTES | ENT_HTML5));

        $registry->register('markdown', static fn(mixed $v): string => MarkdownRenderer::render(self::string($v)));

        $registry->register('wrap', static function (mixed $v, array $p): string {
            $tag = self::string($p[0] ?? 'span');

            return "<$tag>" . self::string($v) . "</$tag>";
        });

        $registry->register('surround', static function (mixed $v, array $p): string {
            $before = self::string($p[0] ?? '');
            $after  = self::string($p[1] ?? $before);

            return $before . self::string($v) . $after;
        });

        $registry->register('add', static fn(mixed $v, array $p): float
            => self::float($v) + self::float($p[0] ?? 0));

        $registry->register('subtract', static fn(mixed $v, array $p): float
            => self::float($v) - self::float($p[0] ?? 0));

        $registry->register('multiply', static fn(mixed $v, array $p): float
            => self::float($v) * self::float($p[0] ?? 1));

        $registry->register('divide', static function (mixed $v, array $p): float|int {
            $divisor = self::float($p[0] ?? 1);

            return $divisor !== 0.0 ? self::float($v) / $divisor : 0;
        });

        $registry->register('mod', static fn(mixed $v, array $p): int
            => self::int($v) % self::int($p[0] ?? 1));

        $registry->register('ceil', static fn(mixed $v): int => (int) ceil(self::float($v)));

        $registry->register('floor', static fn(mixed $v): int => (int) floor(self::float($v)));

        $registry->register('round', static fn(mixed $v, array $p): float
            => round(self::float($v), self::int($p[0] ?? 0)));

        $registry->register('sort', static function (mixed $v, array $p): mixed {
            $items = self::iterableToArray($v);
            if ($items === null) {
                return $v;
            }

            $key = isset($p[0]) ? self::string($p[0]) : null;
            if ($key !== null) {
                usort($items, static fn(mixed $a, mixed $b): int => self::dataGet($a, $key)
                    <=> self::dataGet($b, $key));

                return $items;
            }

            sort($items);

            return $items;
        });

        $registry->register('first', static function (mixed $v, array $p): mixed {
            $items = self::iterableToArray($v);
            if ($items !== null) {
                $n     = self::int($p[0] ?? 1);
                $slice = array_slice($items, 0, $n);

                return $n === 1 ? self::firstValue($slice) : $slice;
            }

            return $v;
        });

        $registry->register('last', static function (mixed $v, array $p): mixed {
            $items = self::iterableToArray($v);
            if ($items !== null) {
                $n = self::int($p[0] ?? 1);
                if ($n === 1) {
                    return self::lastValue($items);
                }

                return array_slice($items, -$n);
            }

            return $v;
        });

        $registry->register('pluck', static function (mixed $v, array $p): mixed {
            $items = self::iterableToArray($v);
            if ($items === null || $p === []) {
                return $v;
            }

            $key = self::parameterKey($p);

            return array_map(
                static fn(mixed $item): mixed => self::dataGet($item, $key),
                $items,
            );
        });

        $registry->register('unique', static function (mixed $v): mixed {
            $items = self::iterableToArray($v);
            if ($items === null) {
                return $v;
            }

            return self::uniqueValues($items);
        });

        $registry->register('flatten', static function (mixed $v): array {
            $result = [];
            self::flattenInto($v, $result);

            return $result;
        });

        $registry->register('keys', static fn(mixed $v): array => array_keys(self::iterableToArray($v) ?? []));

        $registry->register('values', static fn(mixed $v): array => array_values(self::iterableToArray($v) ?? []));

        $registry->register('where', static function (mixed $v, array $p): mixed {
            $items = self::iterableToArray($v);
            if ($items === null || count($p) < 2) {
                return $v;
            }

            $key   = self::parameterKey($p);
            $value = new ValueResult($p[1] ?? null);

            return array_values(array_filter(
                $items,
                static fn(mixed $item): bool => self::dataGet($item, $key) == $value->value,
            ));
        });

        $registry->register('chunk', static function (mixed $v, array $p): mixed {
            $items = self::iterableToArray($v);
            if ($items === null) {
                return $v;
            }

            $size = max(1, self::int($p[0] ?? 2));

            return array_chunk($items, $size);
        });

        $registry->register('join', static function (mixed $v, array $p): string {
            $items = self::iterableToArray($v);
            if ($items === null) {
                return self::string($v);
            }

            $glue  = self::string($p[0] ?? ', ');
            $parts = array_map(self::string(...), $items);

            return implode($glue, $parts);
        });

        $registry->register('explode', static function (mixed $v, array $p): array {
            $sep = self::string($p[0] ?? ',');

            return explode($sep !== '' ? $sep : ',', self::string($v));
        });

        $registry->register('is_empty', static fn(mixed $v): bool => empty($v));

        $registry->register('is_array', static fn(mixed $v): bool => is_array($v));

        $registry->register('is_numeric', static fn(mixed $v): bool => is_numeric($v));

        $registry->register('md5', static fn(mixed $v): string => md5(self::string($v)));

        $registry->register('format', static function (mixed $v, array $p): string {
            $format = self::string($p[0] ?? 'Y-m-d');
            if (is_numeric($v)) {
                return date($format, self::int($v));
            }

            $stringValue = self::string($v);
            $ts          = strtotime($stringValue);

            return $ts !== false ? date($format, $ts) : $stringValue;
        });

        $registry->register('starts_with', static fn(mixed $v, array $p): bool
            => str_starts_with(self::string($v), self::string($p[0] ?? '')));

        $registry->register('ends_with', static fn(mixed $v, array $p): bool
            => str_ends_with(self::string($v), self::string($p[0] ?? '')));

        $registry->register('contains', static fn(mixed $v, array $p): bool
            => str_contains(self::string($v), self::string($p[0] ?? '')));

        $registry->register('repeat', static fn(mixed $v, array $p): string
            => self::unicode($v)->repeat(self::int($p[0] ?? 1))->toString());

        $registry->register('pad', static function (mixed $v, array $p): string {
            $len  = self::int($p[0] ?? 0);
            $char = self::string($p[1] ?? ' ');
            $dir  = self::string($p[2] ?? 'right');

            return match ($dir) {
                'left'  => self::unicode($v)->padStart($len, $char)->toString(),
                'both'  => self::unicode($v)->padBoth($len, $char)->toString(),
                default => self::unicode($v)->padEnd($len, $char)->toString(),
            };
        });
    }

    private static function unicode(mixed $value): UnicodeString
    {
        return new UnicodeString(self::string($value));
    }

    private static function slugger(): AsciiSlugger
    {
        static $slugger = null;

        if (! $slugger instanceof AsciiSlugger) {
            $slugger = new AsciiSlugger();
        }

        return $slugger;
    }

    private static function ucfirst(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return self::unicode($value)
            ->title(true)
            ->slice(0, 1)
            ->append(self::unicode($value)->slice(1)->toString())
            ->toString();
    }

    private static function lcfirst(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return self::unicode($value)
            ->lower()
            ->slice(0, 1)
            ->append(self::unicode($value)->slice(1)->toString())
            ->toString();
    }

    private static function wordCount(string $value): int
    {
        preg_match_all('/[\p{L}\p{N}]+(?:[\'’-][\p{L}\p{N}]+)*/u', $value, $matches);

        return count($matches[0]);
    }

    private static function string(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    private static function int(mixed $value): int
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

    private static function float(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        return 0.0;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function lastValue(array $values): mixed
    {
        $slice = array_slice($values, -1);

        return $slice === [] ? null : $slice[0];
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function firstValue(array $values): mixed
    {
        $slice = array_slice($values, 0, 1);

        return $slice === [] ? null : $slice[0];
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private static function parameterKey(array $params): int|string
    {
        return isset($params[0]) && is_int($params[0])
            ? $params[0]
            : self::string($params[0] ?? null);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<mixed>
     */
    private static function uniqueValues(array $values): array
    {
        /** @var list<mixed> $result */
        $result = [];
        $seen   = [];

        array_walk($values, static function (mixed $value) use (&$result, &$seen): void {
            $hash = self::uniqueHash($value);
            if (isset($seen[$hash])) {
                return;
            }

            $seen[$hash] = true;

            $result = array_merge($result, [$value]);
        });

        return $result;
    }

    private static function uniqueHash(mixed $value): string
    {
        if (is_object($value)) {
            return 'object:' . spl_object_hash($value);
        }

        return 'value:' . serialize($value);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function iterableToArray(mixed $value): ?array
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
     * @param list<mixed> $result
     */
    private static function flattenInto(mixed $value, array &$result): void
    {
        $items = self::iterableToArray($value);
        if ($items === null) {
            $result = array_merge($result, [$value]);

            return;
        }

        foreach ($items as $item) {
            self::flattenInto($item, $result);
        }
    }

    private static function dataGet(mixed $value, int|string $key): mixed
    {
        if (is_array($value)) {
            return $value[$key] ?? null;
        }

        if (is_object($value)) {
            $property = (string) $key;

            if (property_exists($value, $property)) {
                return $value->{$property};
            }

            if (method_exists($value, $property)) {
                return $value->{$property}();
            }

            if (method_exists($value, '__get')) {
                return $value->{$property};
            }

            if ($value instanceof ArrayAccess && $value->offsetExists($key)) {
                return $value[$key];
            }
        }

        return null;
    }
}
