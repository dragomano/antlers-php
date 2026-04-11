<?php

declare(strict_types=1);

namespace Bugo\Antlers\Tags;

use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Runtime\NodeProcessor;

interface TagInterface
{
    /**
     * @param array<string, mixed> $parameters Resolved parameter values
     * @param array<string, mixed> $data Current template scope
     * @param NodeProcessor $processor For rendering children
     * @param AbstractNode[] $children Inner nodes (for paired tags)
     */
    public function handle(
        array $parameters,
        array $data,
        NodeProcessor $processor,
        string $method = 'index',
        array $children = [],
    ): mixed;
}
