<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

/**
 * State that belongs to a single top-level render: sections, stacks, once
 * markers and the counters behind {{ increment }} and {{ switch }}.
 *
 * Created fresh for every root render, so nothing here can survive into the
 * next one — including after a render that threw.
 */
final class RenderState
{
    /** @var array<string, string> */
    private array $sections = [];

    /** @var array<string, list<string>> */
    private array $stacks = [];

    /** @var array<string, true> */
    private array $onceKeys = [];

    /** @var array<string, int> */
    private array $increments = [];

    /** @var array<string, int> */
    private array $switches = [];

    public function storeSection(string $name, string $content, bool $append = false): void
    {
        $this->sections[$name] = $append
            ? ($this->sections[$name] ?? '') . $content
            : $content;
    }

    public function section(string $name): string
    {
        return $this->sections[$name] ?? '';
    }

    public function pushStack(string $name, string $content, bool $prepend = false): void
    {
        $this->stacks[$name] ??= [];

        if ($prepend) {
            array_unshift($this->stacks[$name], $content);

            return;
        }

        $this->stacks[$name][] = $content;
    }

    public function stack(string $name): string
    {
        return implode('', $this->stacks[$name] ?? []);
    }

    /**
     * Marks a key as rendered. Returns false if it already was.
     */
    public function markOnce(string $key): bool
    {
        if (isset($this->onceKeys[$key])) {
            return false;
        }

        $this->onceKeys[$key] = true;

        return true;
    }

    public function onceCount(): int
    {
        return count($this->onceKeys);
    }

    public function nextIncrement(string $name, int $from, int $step): int
    {
        if (! isset($this->increments[$name])) {
            $this->increments[$name] = $from;

            return $from;
        }

        $this->increments[$name] += $step;

        return $this->increments[$name];
    }

    public function nextSwitchIndex(string $name): int
    {
        $index = $this->switches[$name] ?? 0;

        $this->switches[$name] = $index + 1;

        return $index;
    }
}
