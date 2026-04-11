<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class ModifierNode extends AbstractNode
{
    public function __construct(
        public string $name,
        /** @var list<AbstractNode> */
        public array $params = [],
    ) {}
}
