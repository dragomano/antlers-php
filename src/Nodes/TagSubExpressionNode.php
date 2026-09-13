<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class TagSubExpressionNode extends AbstractNode
{
    public function __construct(public readonly TagNode $tag) {}
}
