<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class TagNode extends AbstractNode
{
    public function __construct(
        /** Tag name, e.g. "partial" */
        public string $name,
        /** Method name for namespaced tags, e.g. "load" in "partial:load" */
        public string $method = 'index',
        /** @var array<string, AbstractNode> */
        public array $parameters = [],
        /** @var AbstractNode[] Children for paired tags */
        public array $children = [],
        public bool $isPaired = false,
    ) {}
}
