<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class SwitchNode extends AbstractNode
{
    /**
     * @param list<array{AbstractNode, AbstractNode}> $cases
     */
    public function __construct(
        public array $cases = [],
        public ?AbstractNode $default = null,
    ) {}
}
