<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Tags\TagRegistry;
use Closure;

final class TagInvoker
{
    /** @var list<array{name: string, method: string, line: int, signature: string}> */
    private array $contexts = [];

    /**
     * @param Closure(AbstractNode, array<string, mixed>): mixed $evaluate
     * @param Closure(string, string, array<string, mixed>, array<string, mixed>, AbstractNode[]): mixed $handle
     */
    public function __construct(
        private readonly TagRegistry $tags,
        private readonly RuntimeOptions $options,
        private readonly Closure $evaluate,
        private readonly Closure $handle,
    ) {}

    /** @param array<string, mixed> $scope */
    public function call(TagNode $node, array $scope): mixed
    {
        if (! $this->tags->has($node->name)) {
            return $this->options->fail(sprintf('Unknown tag: "%s"', $node->name));
        }

        if ($this->options->guardPolicy->guardsTag($node->name)) {
            return $this->options->fail(sprintf('Guarded tag: "%s"', $node->name));
        }

        $parameters = array_map(
            fn(AbstractNode $parameter): mixed => ($this->evaluate)($parameter, $scope),
            $node->parameters,
        );
        $parameters = array_filter(
            $parameters,
            static fn(mixed $value): bool => ! $value instanceof VoidValue,
        );

        $this->pushContext($node);

        try {
            return ($this->handle)(
                $node->name,
                $node->method,
                $parameters,
                $scope,
                $node->children,
            );
        } finally {
            array_pop($this->contexts);
        }
    }

    /** @return array{name: string, method: string, line: int, signature: string}|null */
    public function currentContext(): ?array
    {
        $key = array_key_last($this->contexts);

        return $key === null ? null : $this->contexts[$key];
    }

    private function pushContext(TagNode $node): void
    {
        $this->contexts[] = [
            'name'      => $node->name,
            'method'    => $node->method,
            'line'      => $node->line,
            'signature' => hash('sha256', serialize([
                $node->parameters,
                $node->children,
            ])),
        ];
    }
}
