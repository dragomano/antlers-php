<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class BinaryOpNode extends AbstractNode
{
    public function __construct(
        public AbstractNode $left,
        public string $operator,
        public AbstractNode $right,
    ) {}
}
