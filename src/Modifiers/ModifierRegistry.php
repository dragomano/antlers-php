<?php

declare(strict_types=1);

namespace Bugo\Antlers\Modifiers;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Closure;

final class ModifierRegistry
{
    /** @var array<string, ModifierInterface|callable> */
    private array $modifiers = [];

    private ?Closure $loader = null;

    private bool $loading = false;

    /** @var array<string, true>|null */
    private ?array $registrationFilter = null;

    public function setLoader(Closure $loader): void
    {
        $this->loader = $loader;
    }

    public function register(string $name, ModifierInterface|callable $modifier): void
    {
        $this->load();

        if ($this->registrationFilter === null || isset($this->registrationFilter[$name])) {
            $this->modifiers[$name] = $modifier;
        }
    }

    /**
     * @param list<string> $names
     * @param callable(): void $register
     */
    public function registerGroup(array $names, callable $register): void
    {
        $previous = $this->registrationFilter;
        $this->registrationFilter = array_fill_keys($names, true);

        try {
            $register();
        } finally {
            $this->registrationFilter = $previous;
        }
    }

    public function has(string $name): bool
    {
        $this->load();

        return isset($this->modifiers[$name]);
    }

    /**
     * @param list<mixed> $params
     * @param array<string, mixed> $context
     */
    public function apply(string $name, mixed $value, array $params, array $context): mixed
    {
        $this->load();

        $modifier = $this->modifiers[$name]
            ?? throw new AntlersRuntimeException(sprintf('Unknown modifier: "%s"', $name));

        if ($modifier instanceof ModifierInterface) {
            return $modifier->modify($value, $params, $context);
        }

        return ($modifier)($value, $params, $context);
    }

    private function load(): void
    {
        if (! $this->loader instanceof Closure || $this->loading) {
            return;
        }

        $loader = $this->loader;

        $this->loader = null;

        $this->loading = true;

        try {
            $loader();
        } finally {
            $this->loading = false;
        }
    }
}
