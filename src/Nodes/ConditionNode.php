<?php

declare(strict_types=1);

namespace Bugo\Antlers\Nodes;

final class ConditionNode extends AbstractNode
{
    /** @var ConditionBranch[] */
    public array $branches = [];
}
