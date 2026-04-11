<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class BooleanNode extends AbstractNode
{
    public function __construct(public bool $value) {}
}
