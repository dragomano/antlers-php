<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Nodes\AbstractNode;
use Closure;

final class RecursionState
{
    /** @var list<array{children: AbstractNode[], depth: int}> */
    private array $templates = [];

    /**
     * @param AbstractNode[] $children
     * @param Closure(): string $renderer
     */
    public function within(array $children, Closure $renderer): string
    {
        $this->templates[] = ['children' => $children, 'depth' => 0];

        try {
            return $renderer();
        } finally {
            array_pop($this->templates);
        }
    }

    /** @param Closure(AbstractNode[]): string $renderer */
    public function descend(int $maxDepth, Closure $renderer): string
    {
        $key = array_key_last($this->templates);
        if ($key === null) {
            return '';
        }

        $template = $this->templates[$key];
        if ($template['depth'] >= $maxDepth) {
            return '';
        }

        $this->templates[$key] = [
            'children' => $template['children'],
            'depth'    => $template['depth'] + 1,
        ];

        try {
            return $renderer($template['children']);
        } finally {
            $this->templates[$key] = $template;
        }
    }
}
