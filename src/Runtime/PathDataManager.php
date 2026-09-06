<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use ArrayAccess;

/**
 * Resolves dot-notation paths in data arrays/objects.
 * e.g.: get("user.profile.name", $data) → $data['user']['profile']['name']
 */
final class PathDataManager
{
    /**
     * Resolve a path string in the given data scope.
     *
     * Supports:
     *   - Dot notation:    "user.name"        → $data['user']['name']
     *   - Array subscript: "items[0]"         → $data['items'][0]
     *   - Key subscript:   "items[key]"       → $data['items'][$data['key']]
     *   - Literal key:     "items['key']"     → $data['items']['key']
     *   - Object access:   "obj.method"       → $obj->method or $obj->method()
     *
     * @param array<string, mixed> $scope
     */
    public function get(string $path, array $scope): mixed
    {
        if ($path === '') {
            return null;
        }

        $current = new ValueResult($scope);

        foreach ($this->keysFor($path, $scope) as $key) {
            if ($current->value === null) {
                return null;
            }

            $current = $this->accessValue($current->value, $key);
        }

        return $current->value;
    }

    /**
     * Check whether a path exists in the given scope (without resolving the value).
     *
     * @param array<string, mixed> $scope
     */
    public function has(string $path, array $scope): bool
    {
        if ($path === '') {
            return false;
        }

        $current = new ValueResult($scope);

        foreach ($this->keysFor($path, $scope) as $key) {
            if (! $this->keyExists($current->value, $key)) {
                return false;
            }

            $current = $this->accessValue($current->value, $key);
        }

        return true;
    }

    /**
     * Check whether a key exists in an array or object without fetching the value.
     */
    private function keyExists(mixed $container, int|string $key): bool
    {
        if (is_array($container)) {
            return array_key_exists($key, $container);
        }

        if (is_object($container)) {
            $property = (string) $key;

            return property_exists($container, $property)
                || method_exists($container, $property)
                || method_exists($container, '__get')
                || ($container instanceof ArrayAccess && $container->offsetExists($key));
        }

        return false;
    }

    /**
     * Access a key/property/method on a value.
     */
    private function access(mixed $container, int|string $key): mixed
    {
        if (is_array($container)) {
            return $container[$key] ?? null;
        }

        if (is_object($container)) {
            $property = (string) $key;

            // Public property
            if (property_exists($container, $property)) {
                return $container->{$key};
            }

            // Method call (zero arguments)
            if (method_exists($container, $property)) {
                return $container->{$key}();
            }

            // __get magic
            if (method_exists($container, '__get')) {
                return $container->{$key};
            }

            // ArrayAccess
            if ($container instanceof ArrayAccess) {
                return $container[$key] ?? null;
            }
        }

        return null;
    }

    private function accessValue(mixed $container, int|string $key): ValueResult
    {
        return new ValueResult($this->access($container, $key));
    }

    /**
     * Flattens a path into the keys to walk, so get() and has() share one loop.
     *
     * @param  array<string, mixed> $scope
     * @return list<int|string>
     */
    private function keysFor(string $path, array $scope): array
    {
        preg_match_all('/[^.:\[\]]+|\[([^\[\]]*)]/', $path, $matches, PREG_SET_ORDER);

        $keys = [];

        foreach ($matches as $match) {
            $keys[] = isset($match[1])
                ? $this->resolveIndex($scope, $match[1])
                : $match[0];
        }

        return $keys;
    }

    private function stringifyKey(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveIndex(array $scope, string $index): int|string
    {
        $literal = $this->unquote($index);
        if ($literal !== null) {
            return $literal;
        }

        if ($this->has($index, $scope)) {
            return $this->indexKey($this->get($index, $scope));
        }

        if (is_numeric($index)) {
            return (int) $index;
        }

        return $index;
    }

    /** Quotes are the author saying "this key, not the variable named like it". */
    private function unquote(string $index): ?string
    {
        $quote = $index[0] ?? '';

        if (strlen($index) >= 2 && ($quote === "'" || $quote === '"') && str_ends_with($index, $quote)) {
            return substr($index, 1, -1);
        }

        return null;
    }

    private function indexKey(mixed $value): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        return $this->stringifyKey($value);
    }
}
