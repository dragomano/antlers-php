<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\AntlersNode;
use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Parser\LanguageParser;
use Closure;

final readonly class SlotRenderer
{
    /**
     * @param Closure(AbstractNode[], array<string, mixed>): string $renderFragment
     * @param Closure(AbstractNode, array<string, mixed>): mixed $evaluate
     */
    public function __construct(
        private LanguageParser $parser,
        private ExpressionEvaluator $evaluator,
        private Closure $renderFragment,
        private Closure $evaluate,
    ) {}

    /**
     * @param AbstractNode[] $children
     * @param array<string, mixed> $data
     * @return array{default: string, named: array<string, string>}
     */
    public function render(array $children, array $data = []): array
    {
        $defaultChildren = [];
        $namedSlots      = [];

        foreach ($children as $child) {
            $slot = $this->resolveDefinition($child, $data);

            if ($slot === null) {
                $defaultChildren[] = $child;

                continue;
            }

            $namedSlots[$slot['name']] = ($namedSlots[$slot['name']] ?? '')
                . ($this->renderFragment)($slot['children'], $data);
        }

        return [
            'default' => ($this->renderFragment)($defaultChildren, $data),
            'named'   => $namedSlots,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{name: string, children: AbstractNode[]}|null
     */
    private function resolveDefinition(AbstractNode $node, array $scope): ?array
    {
        if ($node instanceof TagNode) {
            $parsed = $node;
        } elseif ($node instanceof AntlersNode && ! $node->isClosingTag) {
            $parsed = $this->parser->parseNode($node);
        } else {
            return null;
        }

        if (! $parsed instanceof TagNode || $parsed->name !== 'slot' || $parsed->children === []) {
            return null;
        }

        return [
            'name'     => $this->name($parsed, $scope) ?? 'default',
            'children' => $parsed->children,
        ];
    }

    /** @param array<string, mixed> $scope */
    private function name(TagNode $node, array $scope): ?string
    {
        if ($node->method !== 'index' && $node->method !== '') {
            return $node->method;
        }

        if (! isset($node->parameters['name'])) {
            return null;
        }

        $name = $this->evaluator->stringify(($this->evaluate)($node->parameters['name'], $scope));

        return $name !== '' ? $name : null;
    }
}
