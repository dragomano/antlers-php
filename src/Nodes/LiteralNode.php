<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class LiteralNode extends AbstractNode
{
    public function __construct(public string $content) {}
}
