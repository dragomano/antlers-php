<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class TernaryNode extends AbstractNode
{
    public function __construct(
        public AbstractNode $condition,
        public AbstractNode $trueBranch,
        public AbstractNode $falseBranch,
    ) {}
}
