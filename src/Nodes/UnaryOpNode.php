<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class UnaryOpNode extends AbstractNode
{
    public function __construct(
        public string $operator,
        public AbstractNode $operand,
    ) {}
}
