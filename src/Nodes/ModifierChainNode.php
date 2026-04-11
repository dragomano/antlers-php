<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class ModifierChainNode extends AbstractNode
{
    public function __construct(
        public AbstractNode $value,
        /** @var list<ModifierNode> */
        public array $modifiers = [],
    ) {}
}
