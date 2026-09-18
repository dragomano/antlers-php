<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Tags\TagRegistry;
use Closure;

final class TagInvoker
{
    /** @var list<array{name: string, method: string, line: int, signature: ?string, node: TagNode}> */
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

    /** @return array{name: string, method: string, line: int, signature: ?string}|null */
    public function currentContext(): ?array
    {
        $key = array_key_last($this->contexts);

        if (! is_int($key)) {
            return null;
        }

        return [
            'name'      => $this->contexts[$key]['name'],
            'method'    => $this->contexts[$key]['method'],
            'line'      => $this->contexts[$key]['line'],
            'signature' => $this->contexts[$key]['signature'],
        ];
    }

    public function currentSignature(): ?string
    {
        $key = array_key_last($this->contexts);

        if (! is_int($key)) {
            return null;
        }

        $context = $this->contexts[$key];

        if ($context['signature'] === null) {
            $node = $context['node'];
            $context['signature'] = hash('sha256', serialize([
                $node->parameters,
                $node->children,
            ]));
            $this->contexts[$key] = $context;
        }

        return $context['signature'];
    }

    private function pushContext(TagNode $node): void
    {
        $this->contexts[] = [
            'name'      => $node->name,
            'method'    => $node->method,
            'line'      => $node->line,
            'signature' => null,
            'node'      => $node,
        ];
    }
}
