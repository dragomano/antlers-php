<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class LoopNode extends AbstractNode
{
    public function __construct(
        /** 'foreach' | 'for' | 'paired' */
        public string $type,
        /** For 'foreach': the iterable expression */
        public ?AbstractNode $iterable = null,
        /** For 'foreach as item': alias name */
        public ?string $alias = null,
        /** For 'foreach as key => value': key alias */
        public ?string $keyAlias = null,
        /** For 'for X to Y': start expression */
        public ?AbstractNode $from = null,
        /** For 'for X to Y': end expression */
        public ?AbstractNode $to = null,
        /** For 'paired': the variable path being iterated */
        public ?string $variablePath = null,
        /** @var AbstractNode[] */
        public array $children = [],
    ) {}
}
