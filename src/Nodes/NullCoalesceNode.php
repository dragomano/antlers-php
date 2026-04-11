<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class NullCoalesceNode extends AbstractNode
{
    public function __construct(
        public AbstractNode $left,
        public AbstractNode $right,
    ) {}
}
