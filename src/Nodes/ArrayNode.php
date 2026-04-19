<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class ArrayNode extends AbstractNode
{
    /**
     * @param list<AbstractNode> $items
     */
    public function __construct(public array $items) {}
}
