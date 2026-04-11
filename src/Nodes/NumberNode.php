<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class NumberNode extends AbstractNode
{
    public function __construct(public int|float $value) {}
}
