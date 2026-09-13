<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Nodes\AbstractNode;
use Closure;

final readonly class LoopRenderer
{
    /** @param Closure(array<string, mixed>, AbstractNode[]): string $renderChildren */
    public function __construct(private Closure $renderChildren) {}

    /** @param AbstractNode[] $children */
    public function renderItems(
        mixed $items,
        array $children,
        ?string $alias = null,
        ?string $keyAlias = null,
    ): string {
        if (! is_iterable($items)) {
            return '';
        }

        $itemArray  = ValueCoercion::toArray($items) ?? [];
        $itemValues = array_values($itemArray);
        $total      = count($itemArray);
        $output     = '';
        $index      = 0;

        array_walk($itemArray, function (mixed $item, int|string $key) use (
            &$children,
            &$output,
            &$itemValues,
            &$total,
            &$index,
            $alias,
            $keyAlias,
        ): void {
            $index++;

            $loopVars = [
                'count' => $index,
                'index' => $index - 1,
                'total' => $total,
                'first' => $index === 1,
                'last'  => $index === $total,
                'odd'   => $index % 2 !== 0,
                'even'  => $index % 2 === 0,
                'key'   => $key,
                'prev'  => $this->normalizeRelativeItem($index > 1 ? ($itemValues[$index - 2] ?? null) : null),
                'next'  => $this->normalizeRelativeItem($index < $total ? ($itemValues[$index] ?? null) : null),
            ];

            $itemScope = ValueCoercion::toScopeFrame($item);
            $loopVars  = $itemScope !== null
                ? array_merge($loopVars, $itemScope)
                : array_merge($loopVars, ['value' => $item]);

            if ($alias !== null) {
                $loopVars = array_merge($loopVars, [$alias => $item]);
            }

            if ($keyAlias !== null) {
                $loopVars[$keyAlias] = $key;
            }

            $output .= ($this->renderChildren)($loopVars, $children);
        });

        return $output;
    }

    /** @param AbstractNode[] $children */
    public function renderCounter(int $from, int $to, array $children): string
    {
        $output = '';
        $step   = $from <= $to ? 1 : -1;
        $total  = abs($to - $from) + 1;
        $index  = 0;

        for ($i = $from; $step > 0 ? $i <= $to : $i >= $to; $i += $step) {
            $index++;

            $output .= ($this->renderChildren)([
                'count' => $index,
                'index' => $index - 1,
                'total' => $total,
                'first' => $index === 1,
                'last'  => $index === $total,
                'odd'   => $index % 2 !== 0,
                'even'  => $index % 2 === 0,
                'value' => $i,
            ], $children);
        }

        return $output;
    }

    /** @return array<array-key, mixed>|null */
    private function normalizeRelativeItem(mixed $item): ?array
    {
        if ($item === null) {
            return null;
        }

        return ValueCoercion::toScopeFrame($item) ?? ['value' => $item];
    }
}
