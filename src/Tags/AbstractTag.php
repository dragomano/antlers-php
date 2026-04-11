<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Runtime\NodeProcessor;

/**
 * Convenience base class for custom tags.
 */
abstract class AbstractTag implements TagInterface
{
    /** @var array<string, mixed> */
    protected array $parameters = [];

    /** @var array<string, mixed> */
    protected array $data = [];

    protected NodeProcessor $processor;

    /** @var AbstractNode[] */
    protected array $children = [];

    protected string $currentMethod = 'index';

    public function handle(
        array $parameters,
        array $data,
        NodeProcessor $processor,
        string $method = 'index',
        array $children = [],
    ): mixed {
        $this->parameters    = $parameters;
        $this->data          = $data;
        $this->processor     = $processor;
        $this->children      = $children;
        $this->currentMethod = $method;

        // Dispatch to method: "load" → load(), "index" → index()
        if ($method !== 'index' && method_exists($this, $method)) {
            return $this->{$method}();
        }

        return $this->index();
    }

    abstract public function index(): mixed;

    /**
     * Get a parameter value with optional default.
     */
    protected function param(string $name, mixed $default = null): mixed
    {
        return $this->parameters[$name] ?? $default;
    }

    /**
     * Render children with optionally merged data.
     *
     * @param array<string, mixed> $extraData
     */
    protected function content(array $extraData = []): string
    {
        return $this->processor->reduce($this->children, array_merge($this->data, $extraData));
    }

    protected function getBool(string $name, bool $default = false): bool
    {
        return $this->toBool($this->param($name), $default);
    }

    private function toBool(mixed $value, bool $default): bool
    {
        return $value === null ? $default : (bool) $value;
    }
}
