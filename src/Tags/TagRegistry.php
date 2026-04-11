<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Runtime\NodeProcessor;

final class TagRegistry
{
    /** @var array<string, TagInterface|callable> */
    private array $tags = [];

    public function register(string $name, TagInterface|callable $handler): void
    {
        $this->tags[$name] = $handler;
    }

    public function has(string $name): bool
    {
        return isset($this->tags[$name]);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $data
     * @param AbstractNode[] $children
     */
    public function handle(
        string $name,
        string $method,
        array $parameters,
        array $data,
        NodeProcessor $processor,
        array $children = [],
    ): mixed {
        if (! isset($this->tags[$name])) {
            return null;
        }

        $handler = $this->tags[$name];

        if ($handler instanceof TagInterface) {
            return $handler->handle($parameters, $data, $processor, $method, $children);
        }

        // Callable: (params, data, processor, method, children) → string|null
        return ($handler)($parameters, $data, $processor, $method, $children);
    }
}
