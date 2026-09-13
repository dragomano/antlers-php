<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

/**
 * The data frame stack of one render.
 *
 * Lookups need a flattened view of globals plus every frame, and rebuilding it
 * per AST node was the single largest cost in rendering, so the view is
 * memoised and invalidated on every mutation.
 */
final class Scope
{
    /** @var array<int, array<string, mixed>> */
    private array $frames = [];

    /** @var array<string, mixed>|null */
    private ?array $flattened = null;

    /** @param array<string, mixed> $globals */
    public function __construct(private readonly array $globals = []) {}

    /** @param array<string, mixed> $frame */
    public function push(array $frame): void
    {
        $this->frames[]  = $frame;
        $this->flattened = null;
    }

    public function pop(): void
    {
        array_pop($this->frames);

        $this->flattened = null;
    }

    public function write(string $name, mixed $value): void
    {
        if ($this->frames === []) {
            $this->frames[] = [];
        }

        $index = count($this->frames) - 1;

        $this->frames[$index][$name] = $value;

        $this->flattened = null;
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $key = array_key_last($this->frames);

        return $key === null ? $this->globals : $this->frames[$key];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->flattened ??= array_merge($this->globals, ...$this->frames);
    }
}
