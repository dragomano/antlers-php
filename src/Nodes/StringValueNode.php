<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class StringValueNode extends AbstractNode
{
    /** @var list<string|AbstractNode> Parts: plain strings or interpolated expression nodes */
    public array $parts = [];

    public function __construct(
        public string $value,
        /** Whether the string contains {var} interpolations */
        public bool $hasInterpolations = false,
    ) {}
}
