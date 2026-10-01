<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Runtime\NodeProcessor;

final class TagRegistry
{
    /** @var array<string, TagInterface|callable> */
    private array $tags = [];

    // The registry and every lookup live in lowercase: template spelling and
    // registration casing must not decide which forms resolve.
    public function register(string $name, TagInterface|callable $handler): void
    {
        $this->tags[strtolower($name)] = $handler;
    }

    public function has(string $name): bool
    {
        return isset($this->tags[strtolower($name)]);
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
        $name = strtolower($name);

        $handler = $this->tags[$name]
            ?? throw new AntlersRuntimeException(sprintf('Unknown tag: "%s"', $name));

        if ($handler instanceof TagInterface) {
            return $handler->handle(new TagContext($name, $method, $parameters, $data, $children, $processor));
        }

        return ($handler)($parameters, $data, $processor, $method, $children);
    }
}
