<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class CollectionOperationNode extends AbstractNode
{
    /**
     * @param list<CollectionOperatorNode> $operators
     */
    public function __construct(
        public AbstractNode $value,
        public array $operators,
    ) {}
}
