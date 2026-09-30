<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use ArrayAccess;
use ReflectionMethod;
use Throwable;

final class ObjectAccess
{
    public static function has(mixed $container, int|string $key, RuntimeOptions $options): bool
    {
        if (is_array($container)) {
            return array_key_exists($key, $container);
        }

        if (! is_object($container)) {
            return false;
        }

        $property = (string) $key;

        if (self::isPublicProperty($container, $property)) {
            return true;
        }

        if ($options->allowObjectMethodCalls && self::isPublicMethod($container, $property)) {
            return true;
        }

        // __isset answers "does this key exist?" the way isset() would; without
        // it __get is best-effort and any key on the object counts as present.
        if (method_exists($container, '__isset')) {
            try {
                return (bool) $container->__isset($property);
            } catch (Throwable $e) {
                return self::accessFailed($options, $container, $property, $e, false);
            }
        }

        if (method_exists($container, '__get')) {
            return true;
        }

        return $container instanceof ArrayAccess && $container->offsetExists($key);
    }

    /**
     * Read a key/property/method from a value.
     */
    public static function read(mixed $container, int|string $key, RuntimeOptions $options): mixed
    {
        if (is_array($container)) {
            return $container[$key] ?? null;
        }

        if (! is_object($container)) {
            return null;
        }

        $property = (string) $key;

        try {
            if (self::isPublicProperty($container, $property)) {
                return $container->{$key};
            }

            if ($options->allowObjectMethodCalls && self::isPublicMethod($container, $property)) {
                // isPublicMethod() has just proven this one callable; neither
                // analyzer can follow a data-driven method name on its own.
                /** @var callable $method */
                $method = [$container, $key];

                return $method();
            }

            if (method_exists($container, '__get')) {
                return $container->{$key};
            }

            if ($container instanceof ArrayAccess) {
                return $container[$key] ?? null;
            }
        } catch (Throwable $e) {
            return self::accessFailed($options, $container, $property, $e, null);
        }

        return null;
    }

    /**
     * Scope-aware: get_object_vars() yields public properties only from here,
     * so private and protected never come back as readable.
     */
    private static function isPublicProperty(object $container, string $property): bool
    {
        return array_key_exists($property, get_object_vars($container));
    }

    /**
     * Symmetric with isPublicProperty(): a private or protected method is not a
     * reachable member either. method_exists() ignores visibility, so it alone
     * would let {{ obj.privateMethod }} reach the call and only fail inside it.
     */
    private static function isPublicMethod(object $container, string $method): bool
    {
        return method_exists($container, $method)
            && (new ReflectionMethod($container, $method))->isPublic();
    }

    /**
     * Routes a Throwable from an object member through the lenient/strict
     * policy. Fallback is caller-chosen (null for read(), false for has()): any
     * other unresolved member returns those too, so `?? "fallback"` can still
     * tell the difference.
     *
     * @template T
     * @param  T $fallback
     * @return T
     */
    private static function accessFailed(
        RuntimeOptions $options,
        object $container,
        string $property,
        Throwable $e,
        mixed $fallback,
    ): mixed {
        return $options->fail(sprintf(
            'Object access failed for %s::$%s: %s',
            $container::class,
            $property,
            $e->getMessage(),
        ), $fallback);
    }
}
