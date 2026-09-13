<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Runtime\NodeProcessor;
use ReflectionMethod;

abstract class AbstractTag implements TagInterface
{
    /** @var array<string, mixed> */
    protected array $parameters = [];

    /** @var array<string, mixed> */
    protected array $data = [];

    protected ?NodeProcessor $processor = null;

    /** @var AbstractNode[] */
    protected array $children = [];

    protected string $currentMethod = 'index';

    /** @var list<TagContext> */
    private array $contexts = [];

    final public function handle(TagContext $context): mixed
    {
        $previousParameters = $this->parameters;
        $previousData       = $this->data;
        $previousChildren   = $this->children;
        $previousMethod     = $this->currentMethod;
        $previousProcessor  = $this->processor;

        $this->parameters    = $context->parameters;
        $this->data          = $context->data;
        $this->processor     = $context->processor();
        $this->children      = $context->children;
        $this->currentMethod = $context->method;
        $this->contexts[]    = $context;

        try {
            return $this->invoke($context);
        } finally {
            array_pop($this->contexts);

            $this->parameters    = $previousParameters;
            $this->data          = $previousData;
            $this->processor     = $previousProcessor;
            $this->children      = $previousChildren;
            $this->currentMethod = $previousMethod;
        }
    }

    private function invoke(TagContext $context): mixed
    {
        $methodName = $context->method;
        if (! method_exists($this, $methodName)) {
            return $context->fail(sprintf('Unknown method "%s" on tag "%s".', $context->method, $context->name));
        }

        $method = new ReflectionMethod($this, $methodName);
        if (! $method->isPublic() || $method->getDeclaringClass()->getName() === self::class) {
            return $context->fail(sprintf('Unknown method "%s" on tag "%s".', $context->method, $context->name));
        }

        return match ($method->getNumberOfParameters()) {
            0       => $method->invoke($this),
            1       => $method->invoke($this, $context),
            default => $context->fail(sprintf('Tag method "%s:%s" must accept at most one TagContext.', $context->name, $context->method)),
        };
    }

    protected function param(string $name, mixed $default = null): mixed
    {
        return $this->context()->param($name, $default);
    }

    /** @param array<string, mixed> $extraData */
    protected function content(array $extraData = []): string
    {
        return $this->context()->content($extraData);
    }

    protected function getBool(string $name, bool $default = false): bool
    {
        return $this->context()->bool($name, $default);
    }

    private function context(): TagContext
    {
        /** @var TagContext $context */
        $context = end($this->contexts);

        return $context;
    }
}
