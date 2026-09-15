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

            $loopVars = $this->loopVariables(
                $index,
                $total,
                $key,
                $index > 1 ? ($itemValues[$index - 2] ?? null) : null,
                $index < $total ? ($itemValues[$index] ?? null) : null,
            );

            $itemScope = ValueCoercion::toScopeFrame($item) ?? [];
            $loopVars  = array_merge($loopVars, array_diff_key($itemScope, $loopVars));

            if ($itemScope === []) {
                $loopVars = array_merge($loopVars, ['value' => $item]);
            }

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

            $loopVars = $this->loopVariables(
                $index,
                $total,
                $index - 1,
                $index > 1 ? $i - $step : null,
                $index < $total ? $i + $step : null,
            );

            $output .= ($this->renderChildren)(array_merge($loopVars, ['value' => $i]), $children);
        }

        return $output;
    }

    /** @return array<string, mixed> */
    private function loopVariables(int $position, int $total, int|string $key, mixed $prev, mixed $next): array
    {
        return [
            'count'         => $position,
            'index'         => $position - 1,
            'total'         => $total,
            'total_results' => $total,
            'no_results'    => false,
            'first'         => $position === 1,
            'last'          => $position === $total,
            'odd'           => $position % 2 !== 0,
            'even'          => $position % 2 === 0,
            'key'           => $key,
            'prev'          => $this->normalizeRelativeItem($prev),
            'next'          => $this->normalizeRelativeItem($next),
        ];
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
