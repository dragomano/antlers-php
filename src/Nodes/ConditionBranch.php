<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class ConditionBranch
{
    public function __construct(
        /** 'if' | 'elseif' | 'unless' | 'else' */
        public string $type,
        public ?AbstractNode $condition,
        /** @var AbstractNode[] */
        public array $children = [],
    ) {}
}
