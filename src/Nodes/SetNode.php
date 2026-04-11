<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class SetNode extends AbstractNode
{
    public function __construct(
        public string $variableName,
        public AbstractNode $value,
    ) {}
}
