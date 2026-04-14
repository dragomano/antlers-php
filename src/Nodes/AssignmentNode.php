<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class AssignmentNode extends AbstractNode
{
    /**
     * @param AbstractNode[] $children
     */
    public function __construct(
        public string $variableName,
        public AbstractNode $value,
        public array $children = [],
    ) {}
}
