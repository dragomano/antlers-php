<?php

declare(strict_types=1);

namespace Bugo\Antlers\Modifiers;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;

final class ModifierRegistry
{
    /** @var array<string, ModifierInterface|callable> */
    private array $modifiers = [];

    public function register(string $name, ModifierInterface|callable $modifier): void
    {
        $this->modifiers[$name] = $modifier;
    }

    public function has(string $name): bool
    {
        return isset($this->modifiers[$name]);
    }

    /**
     * @param list<mixed> $params
     * @param array<string, mixed> $context
     */
    public function apply(string $name, mixed $value, array $params, array $context): mixed
    {
        $modifier = $this->modifiers[$name]
            ?? throw new AntlersRuntimeException(sprintf('Unknown modifier: "%s"', $name));

        if ($modifier instanceof ModifierInterface) {
            return $modifier->modify($value, $params, $context);
        }

        // Callable
        return ($modifier)($value, $params, $context);
    }
}
