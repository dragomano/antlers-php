<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class CollectionOperatorNode
{
    /**
     * @param list<AbstractNode|CollectionGroupArgument|CollectionSortArgument> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public ?string $valuesAlias = null,
        public ?string $scopeAlias = null,
    ) {}
}
