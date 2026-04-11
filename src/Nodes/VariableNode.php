<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class VariableNode extends AbstractNode
{
    public function __construct(
        /** Dot-separated path, e.g. "user.profile.name" */
        public string $path,
    ) {}
}
