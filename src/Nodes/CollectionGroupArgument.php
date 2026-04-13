<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class CollectionGroupArgument
{
    public function __construct(
        public AbstractNode $field,
        public ?string $alias = null,
    ) {}
}
