<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Runtime\NodeProcessor;

final readonly class TagContext
{
    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    public function __construct(
        public string $name,
        public string $method,
        public array $parameters,
        public array $data,
        public array $children,
        private NodeProcessor $processor,
    ) {}

    public function param(string $name, mixed $default = null): mixed
    {
        return $this->parameters[$name] ?? $default;
    }

    public function bool(string $name, bool $default = false): bool
    {
        return array_key_exists($name, $this->parameters)
            ? (bool) $this->parameters[$name]
            : $default;
    }

    /** @param array<string, mixed> $extraData */
    public function content(array $extraData = []): string
    {
        return $this->processor->renderFragment($this->children, array_merge($this->data, $extraData));
    }

    public function processor(): NodeProcessor
    {
        return $this->processor;
    }

    /**
     * @template T
     * @param T $fallback
     * @return T
     */
    public function fail(string $reason, mixed $fallback = ''): mixed
    {
        return $this->processor->fail($reason, $fallback);
    }
}
