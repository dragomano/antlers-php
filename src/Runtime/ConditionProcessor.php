<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\ConditionNode;

/**
 * Evaluates if/elseif/else/unless condition chains.
 * Returns the children of the first matching branch (lazy evaluation).
 */
final readonly class ConditionProcessor
{
    public function __construct(private ExpressionEvaluator $evaluator) {}

    /**
     * @param array<string, mixed> $scope
     * @return AbstractNode[] The children of the matching branch, or [] if none match
     */
    public function process(ConditionNode $node, array $scope): array
    {
        foreach ($node->branches as $branch) {
            if ($branch->type === 'else') {
                return $branch->children;
            }

            if ($branch->condition === null) {
                continue;
            }

            $truthy = $this->evaluator->evaluateTruthy($branch->condition, $scope);

            // 'unless' inverts the condition
            if ($branch->type === 'unless') {
                $truthy = ! $truthy;
            }

            if ($truthy) {
                return $branch->children;
            }
        }

        return [];
    }
}
