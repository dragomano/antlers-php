<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class GatekeeperNode extends AbstractNode
{
    public function __construct(
        public AbstractNode $condition,
        public AbstractNode $right,
    ) {}
}
