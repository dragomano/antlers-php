<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class CollectionSortArgument
{
    public function __construct(
        public AbstractNode $field,
        public ?AbstractNode $direction = null,
    ) {}
}
