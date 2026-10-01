<?php

declare(strict_types=1);

namespace Bugo\Antlers\Modifiers;

use Bugo\Antlers\Runtime\ObjectAccess;
use Bugo\Antlers\Runtime\PathDataManager;
use Bugo\Antlers\Runtime\RuntimeOptions;
use Bugo\Antlers\Runtime\ValueCoercion;
use Bugo\Antlers\Runtime\ValueResult;
use Bugo\Antlers\Tags\CoreTags;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\UnicodeString;

/**
 * Registers all built-in Antlers modifiers.
 */
final class CoreModifiers
{
    /** @var array<string, bool|string> */
    private static array $regexPatternErrors = [];

    public static function register(ModifierRegistry $registry, RuntimeOptions $options): void
    {
        StringModifiers::register($registry, $options);
        ArrayModifiers::register($registry, $options);
        MathModifiers::register($registry, $options);
        UtilityModifiers::register($registry, $options);
        MarkupModifiers::register($registry, $options);
    }

    public static function registerAll(ModifierRegistry $registry, RuntimeOptions $options): void
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

        $registry->register('regex_replace', static function (mixed $v, array $p) use ($options): string {
            $pattern = self::string($p[0] ?? '');
            $subject = self::string($v);
            if ($pattern === '') {
                return $subject;
            }

            if (! isset(self::$regexPatternErrors[$pattern])) {
                set_error_handler(static fn(): bool => true);

                try {
                    preg_match($pattern, '');
                    self::$regexPatternErrors[$pattern] = preg_last_error() === PREG_NO_ERROR
                        ? true
                        : preg_last_error_msg();
                } finally {
                    restore_error_handler();
                }
            }

            if (is_string(self::$regexPatternErrors[$pattern])) {
                return $options->fail(
                    sprintf('regex_replace failed for pattern "%s": %s', $pattern, self::$regexPatternErrors[$pattern]),
                    $subject,
                );
            }

            $replaced = preg_replace($pattern, self::string($p[1] ?? ''), $subject);
            if ($replaced === null) {
                return $options->fail(
                    sprintf('regex_replace failed for pattern "%s": %s', $pattern, preg_last_error_msg()),
                    $subject,
                );
            }

            return $replaced;
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

        $registry->register('markdown', static fn(mixed $v): string
            => $options->markdownRenderer()->render(self::string($v)));

        $registry->register('wrap', static function (mixed $v, array $p): string {
            $tag = self::string($p[0] ?? 'span');

            if (preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $tag) !== 1) {
                return self::string($v);
            }

            return sprintf('<%s>', $tag) . self::string($v) . sprintf('</%s>', $tag);
        });

        $registry->register('surround', static function (mixed $v, array $p): string {
            $before = self::string($p[0] ?? '');
            $after  = self::string($p[1] ?? $before);

            return $before . self::string($v) . $after;
        });

        $registry->register('add', static fn(mixed $v, array $p): int|float
            => ValueCoercion::add(ValueCoercion::toNumber($v), ValueCoercion::toNumber($p[0] ?? 0)));

        $registry->register('subtract', static fn(mixed $v, array $p): int|float
            => ValueCoercion::subtract(ValueCoercion::toNumber($v), ValueCoercion::toNumber($p[0] ?? 0)));

        $registry->register('multiply', static fn(mixed $v, array $p): int|float
            => ValueCoercion::multiply(ValueCoercion::toNumber($v), ValueCoercion::toNumber($p[0] ?? 1)));

        $registry->register('divide', static function (mixed $v, array $p): int|float {
            $divisor = ValueCoercion::toNumber($p[0] ?? 1);

            return $divisor != 0 ? ValueCoercion::divide(ValueCoercion::toNumber($v), $divisor) : 0;
        });

        $registry->register('mod', static function (mixed $v, array $p): int {
            $divisor = self::int($p[0] ?? 1);

            return $divisor !== 0 ? self::int($v) % $divisor : 0;
        });

        $registry->register('ceil', static fn(mixed $v): int => (int) ceil(self::float($v)));

        $registry->register('floor', static fn(mixed $v): int => (int) floor(self::float($v)));

        $registry->register('round', static fn(mixed $v, array $p): float
            => round(self::float($v), self::int($p[0] ?? 0)));

        $registry->register('sort', static function (mixed $v, array $p) use ($options): mixed {
            $items = self::iterableToArray($v);
            if ($items === null) {
                return $v;
            }

            $isList    = array_is_list($items);
            $first     = isset($p[0]) ? strtolower(self::string($p[0])) : null;
            $key       = $first === 'asc' || $first === 'desc' ? null : $first;
            $direction = $key === null ? ($first ?? 'asc') : strtolower(self::string($p[1] ?? 'asc'));
            $direction = $direction === 'desc' ? -1 : 1;

            if ($key !== null) {
                uasort($items, static fn(mixed $a, mixed $b): int
                    => $direction * ValueCoercion::compare(self::dataGet($a, $key, $options), self::dataGet($b, $key, $options)));
            }

            if ($key === null) {
                uasort($items, static fn(mixed $a, mixed $b): int => $direction * ValueCoercion::compare($a, $b));
            }

            return $isList ? array_values($items) : $items;
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

        $registry->register('pluck', static function (mixed $v, array $p) use ($options): mixed {
            $items = self::iterableToArray($v);
            if ($items === null || $p === []) {
                return $v;
            }

            $key = self::parameterKey($p);

            return array_map(
                static fn(mixed $item): mixed => self::dataGet($item, $key, $options),
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

        $registry->register('flatten', static fn(mixed $v): array => iterator_to_array(self::flattenLeaves($v), false));

        $registry->register('keys', static fn(mixed $v): array => array_keys(self::iterableToArray($v) ?? []));

        $registry->register('values', static fn(mixed $v): array => array_values(self::iterableToArray($v) ?? []));

        $registry->register('where', static function (mixed $v, array $p) use ($options): mixed {
            $items = self::iterableToArray($v);
            if ($items === null || count($p) < 2) {
                return $v;
            }

            $key   = self::parameterKey($p);
            $value = new ValueResult($p[1] ?? null);

            return array_values(array_filter(
                $items,
                static fn(mixed $item): bool => self::dataGet($item, $key, $options) == $value->value,
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

        $registry->register('type_of', static fn(mixed $v): string => gettype($v));

        $registry->register('dump', static fn(mixed $v): string
            => $options->debug ? CoreTags::dumpView($v) : '');

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

        $registry->register('contains_all', static fn(mixed $v, array $p): bool
            => self::containsNeedles(self::string($v), self::needles($p), true));

        $registry->register('contains_any', static fn(mixed $v, array $p): bool
            => self::containsNeedles(self::string($v), self::needles($p), false));

        $registry->register('ensure_left', static fn(mixed $v, array $p): string
            => self::unicode($v)->ensureStart(self::string($p[0] ?? ''))->toString());

        $registry->register('ensure_right', static fn(mixed $v, array $p): string
            => self::unicode($v)->ensureEnd(self::string($p[0] ?? ''))->toString());

        $registry->register('remove_left', static fn(mixed $v, array $p): string
            => self::unicode($v)->trimPrefix(self::string($p[0] ?? ''))->toString());

        $registry->register('remove_right', static fn(mixed $v, array $p): string
            => self::unicode($v)->trimSuffix(self::string($p[0] ?? ''))->toString());

        $registry->register('substr', static fn(mixed $v, array $p): string
            => self::unicode($v)->slice(self::int($p[0] ?? 0), isset($p[1]) ? self::int($p[1]) : null)->toString());

        $registry->register('sum', static function (mixed $v, array $p) use ($options): int|float {
            $items = self::iterableToArray($v) ?? [$v];
            $key   = isset($p[0]) ? self::parameterKey($p) : null;

            $sum = array_reduce(
                $items,
                static fn(int|float $carry, mixed $item): int|float => ValueCoercion::add(
                    $carry,
                    ValueCoercion::toNumber($key === null ? $item : self::dataGet($item, $key, $options)),
                ),
                0,
            );

            return is_float($sum) && $sum === round($sum) ? (int) $sum : $sum;
        });

        $registry->register('filter_empty', static function (mixed $v): mixed {
            $items = self::iterableToArray($v);

            return $items === null ? $v : array_filter($items);
        });

        $registry->register('compact', static function (mixed $v, array $p, array $context) use ($options): array {
            /** @var array<string, mixed> $context */
            $paths = new PathDataManager($options);
            $paths = new PathDataManager($options);

            return array_map(
                static fn(string $name): mixed => $paths->get(trim($name), $context),
                explode(',', self::string($v)),
            );
        });

        $registry->register('offset', static function (mixed $v, array $p): mixed {
            $items = self::iterableToArray($v);

            return $items === null ? $v : array_values(array_slice($items, self::int($p[0] ?? 0)));
        });

        $registry->register('shuffle', static function (mixed $v): array|string {
            $items = self::iterableToArray($v);
            if ($items !== null) {
                shuffle($items);

                return $items;
            }

            $chars = preg_split('//u', self::string($v), -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) {
                return self::string($v);
            }

            shuffle($chars);

            return implode('', $chars);
        });

        $registry->register('random', static function (mixed $v): mixed {
            $items = self::iterableToArray($v);
            if ($items === null) {
                return $v;
            }

            return $items === [] ? null : $items[array_rand($items)];
        });

        $registry->register('ascii', static fn(mixed $v): string => self::unicode($v)->ascii()->toString());

        $registry->register('camelize', static fn(mixed $v): string => self::unicode($v)->camel()->toString());

        $registry->register('dashify', static fn(mixed $v): string => self::unicode($v)->kebab()->toString());

        $registry->register('deslugify', static fn(mixed $v): string
            => trim(str_replace(['-', '_'], ' ', self::string($v)), ' '));

        $registry->register('headline', static fn(mixed $v, array $p): string
            => self::headline(self::string($v), self::string($p[0] ?? 'ap') === 'mla'));

        $registry->register('excerpt', static function (mixed $v, array $p): mixed {
            $items = self::iterableToArray($v);
            if ($items !== null) {
                return $items;
            }

            $before = strstr(self::string($v), self::string($p[0] ?? '<!--more-->'), true);

            return $before === false ? '' : $before;
        });

        $registry->register('to_json', static function (mixed $v, array $p): string {
            $encoded = json_encode($v, self::string($p[0] ?? '') === 'pretty' ? JSON_PRETTY_PRINT : 0);

            return $encoded === false ? '' : $encoded;
        });

        $registry->register('to_qs', static function (mixed $v): mixed {
            $items = self::iterableToArray($v);

            return $items === null ? $v : http_build_query($items, '', '&', PHP_QUERY_RFC3986);
        });

        $registry->register('parse_url', static function (mixed $v, array $p): mixed {
            $components = [
                'scheme'   => PHP_URL_SCHEME,
                'host'     => PHP_URL_HOST,
                'port'     => PHP_URL_PORT,
                'user'     => PHP_URL_USER,
                'pass'     => PHP_URL_PASS,
                'path'     => PHP_URL_PATH,
                'query'    => PHP_URL_QUERY,
                'fragment' => PHP_URL_FRAGMENT,
            ];

            $key = isset($p[0]) ? strtolower(self::string($p[0])) : null;

            if ($key === null) {
                // An unparseable URL yields false, which must not display as the word "false".
                $parts = parse_url(self::string($v));

                return $parts === false ? [] : $parts;
            }

            return isset($components[$key]) ? parse_url(self::string($v), $components[$key]) : $v;
        });

        $registry->register('pathinfo', static function (mixed $v, array $p): mixed {
            $components = [
                'dirname'   => PATHINFO_DIRNAME,
                'basename'  => PATHINFO_BASENAME,
                'extension' => PATHINFO_EXTENSION,
                'filename'  => PATHINFO_FILENAME,
            ];

            $key = isset($p[0]) ? strtolower(self::string($p[0])) : null;

            if ($key === null) {
                return pathinfo(self::string($v));
            }

            return isset($components[$key]) ? pathinfo(self::string($v), $components[$key]) : $v;
        });

        $registry->register('rawurlencode', static fn(mixed $v): string
            => implode('/', array_map(rawurlencode(...), explode('/', self::string($v)))));

        $registry->register('urlencode', static fn(mixed $v): string
            => implode('/', array_map(urlencode(...), explode('/', self::string($v)))));

        $registry->register('urldecode', static fn(mixed $v): string => urldecode(self::string($v)));

        $registry->register('timestamp', static function (mixed $v): mixed {
            $date = self::dateValue($v);

            return $date?->getTimestamp() ?? $v;
        });

        $registry->register('ago', static function (mixed $v): mixed {
            $date = self::dateValue($v);

            return $date instanceof DateTimeInterface ? self::diffForHumans(new DateTimeImmutable('now'), $date) : $v;
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
        return ValueCoercion::toString($value);
    }

    private static function int(mixed $value): int
    {
        return ValueCoercion::toInt($value);
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
        $seen = [];

        return array_values(array_filter($values, static function (mixed $value) use (&$seen): bool {
            $hash = self::uniqueHash($value);
            if (isset($seen[$hash])) {
                return false;
            }

            $seen[$hash] = true;

            return true;
        }));
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
        return ValueCoercion::toArray($value);
    }

    /**
     * @return Generator<int, mixed>
     */
    private static function flattenLeaves(mixed $value): Generator
    {
        $items = self::iterableToArray($value);
        if ($items === null) {
            yield $value;

            return;
        }

        foreach (array_keys($items) as $key) {
            yield from self::flattenLeaves($items[$key]);
        }
    }

    private static function dataGet(mixed $value, int|string $key, RuntimeOptions $options): mixed
    {
        return ObjectAccess::read($value, $key, $options);
    }

    /**
     * @param  array<array-key, mixed>  $params
     * @return list<string>
     */
    private static function needles(array $params): array
    {
        $needles = [];

        array_walk_recursive($params, static function (mixed $needle) use (&$needles): void {
            $needles[] = self::string($needle);
        });

        return $needles;
    }

    /**
     * @param  list<string>  $needles
     */
    private static function containsNeedles(string $haystack, array $needles, bool $all): bool
    {
        foreach ($needles as $needle) {
            $found = mb_stripos($haystack, $needle) !== false;

            if ($all) {
                if (! $found) {
                    return false;
                }
            } elseif ($found) {
                return true;
            }
        }

        return $all;
    }

    private static function headline(string $value, bool $mla): string
    {
        $exceptions = [
            'a',
            'an',
            'and',
            'as',
            'at',
            'but',
            'by',
            'for',
            'if',
            'in',
            'is',
            'nor',
            'of',
            'on',
            'or',
            'per',
            'the',
            'to',
            'vs',
            'with',
        ];

        $words = explode(' ', $value);
        $last  = count($words) - 1;

        foreach ($words as $index => $word) {
            $word        = mb_strtolower($word);
            $firstOrLast = $index === 0 || $index === $last;

            $words[$index] = ! $mla && str_contains($word, '-')
                ? implode('-', array_map(
                    static fn(string $subWord): string => $firstOrLast || ! in_array($subWord, $exceptions, true)
                        ? self::ucfirst($subWord)
                        : $subWord,
                    explode('-', $word),
                ))
                : ($firstOrLast || ! in_array($word, $exceptions, true) ? self::ucfirst($word) : $word);
        }

        return implode(' ', $words);
    }

    private static function dateValue(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_int($value)) {
            return new DateTimeImmutable('@' . $value);
        }

        $stringValue = self::string($value);

        if (is_numeric($stringValue)) {
            return new DateTimeImmutable('@' . (int) $stringValue);
        }

        $timestamp = strtotime($stringValue);

        return $timestamp === false ? null : new DateTimeImmutable('@' . $timestamp);
    }

    private static function diffForHumans(DateTimeInterface $now, DateTimeInterface $then): string
    {
        $interval = $now->diff($then);

        [$amount, $unit] = match (true) {
            $interval->y > 0 => [$interval->y, 'year'],
            $interval->m > 0 => [$interval->m, 'month'],
            $interval->d > 0 => [$interval->d, 'day'],
            $interval->h > 0 => [$interval->h, 'hour'],
            $interval->i > 0 => [$interval->i, 'minute'],
            default          => [max($interval->s, 1), 'second'],
        };

        $unit = $amount === 1 ? $unit : $unit . 's';

        return $then->getTimestamp() <= $now->getTimestamp()
            ? sprintf('%d %s ago', $amount, $unit)
            : sprintf('in %d %s', $amount, $unit);
    }
}
