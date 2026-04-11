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
     *   - Object access:   "obj.method"       → $obj->method or $obj->method()
     *
     * @param array<string, mixed> $scope
     */
    public function get(string $path, array $scope): mixed
    {
        if ($path === '') {
            return null;
        }

        $segments = $this->splitPath($path);
        $current  = new ValueResult($scope);

        foreach ($segments as $segment) {
            if ($current->value === null) {
                return null;
            }

            // Array subscript notation: segment = "items[0]" or "items[key]"
            if (preg_match('/^(\w+)\[(.+?)]$/', $segment, $m)) {
                $key   = $m[1];
                $index = $m[2];

                $current = $this->accessValue($current->value, $key);
                if ($current->value === null) {
                    return null;
                }

                $current = $this->accessValue($current->value, $this->resolveIndex($scope, $index));

                continue;
            }

            $current = $this->accessValue($current->value, $segment);
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

        $segments = $this->splitPath($path);
        $current  = new ValueResult($scope);

        foreach ($segments as $segment) {
            if ($current->value === null) {
                return false;
            }

            if (preg_match('/^(\w+)\[(.+?)]$/', $segment, $m)) {
                if (! $this->keyExists($current->value, $m[1])) {
                    return false;
                }

                $current = $this->accessValue($current->value, $m[1]);
                $index   = $this->resolveIndex($scope, $m[2]);

                if (! $this->keyExists($current->value, $index)) {
                    return false;
                }

                $current = $this->accessValue($current->value, $index);

                continue;
            }

            if (! $this->keyExists($current->value, $segment)) {
                return false;
            }

            $current = $this->accessValue($current->value, $segment);
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
     * Split "user.profile.name" into ["user", "profile", "name"].
     * Handles segments that themselves contain array subscripts.
     *
     * @return string[]
     */
    private function splitPath(string $path): array
    {
        $path = str_replace(':', '.', $path);

        // We can't just explode('.') because "items[0]" has no dot but is one segment.
        // Also, "a.b.c" → ["a","b","c"]
        // Edge case: "obj.items[0].name" → ["obj", "items[0]", "name"]
        return explode('.', $path);
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
        if (isset($scope[$index])) {
            return $this->indexKey($scope[$index]);
        }

        if (is_numeric($index)) {
            return (int) $index;
        }

        return $index;
    }

    private function indexKey(mixed $value): int|string
    {
        if (is_int($value) || is_string($value)) {
            return $value;
        }

        return $this->stringifyKey($value);
    }
}
