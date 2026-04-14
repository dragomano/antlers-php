<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class SequenceNode extends AbstractNode
{
    /**
     * @param list<AbstractNode> $statements
     */
    public function __construct(public array $statements) {}
}
