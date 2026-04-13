<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\BinaryOpNode;
use Bugo\Antlers\Nodes\BooleanNode;
use Bugo\Antlers\Nodes\CollectionGroupArgument;
use Bugo\Antlers\Nodes\CollectionOperationNode;
use Bugo\Antlers\Nodes\CollectionOperatorNode;
use Bugo\Antlers\Nodes\CollectionSortArgument;
use Bugo\Antlers\Nodes\GatekeeperNode;
use Bugo\Antlers\Nodes\ModifierChainNode;
use Bugo\Antlers\Nodes\NullCoalesceNode;
use Bugo\Antlers\Nodes\NullNode;
use Bugo\Antlers\Nodes\NumberNode;
use Bugo\Antlers\Nodes\StringValueNode;
use Bugo\Antlers\Nodes\TernaryNode;
use Bugo\Antlers\Nodes\UnaryOpNode;
use Bugo\Antlers\Nodes\VariableNode;
use Traversable;

/**
 * Evaluates expression AST nodes against a data scope.
 */
final class ExpressionEvaluator
{
    private bool $strict = false;

    public function __construct(
        private readonly PathDataManager $paths,
        private readonly ModifierRunner $modifiers,
    ) {}

    public function setStrict(bool $strict): void
    {
        $this->strict = $strict;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function evaluate(AbstractNode $node, array $scope): mixed
    {
        return match (true) {
            $node instanceof NumberNode              => $node->value,
            $node instanceof BooleanNode             => $node->value,
            $node instanceof NullNode                => null,
            $node instanceof StringValueNode         => $this->evalString($node, $scope),
            $node instanceof VariableNode            => $this->resolveVariable($node->path, $scope),
            $node instanceof BinaryOpNode            => $this->evalBinary($node, $scope),
            $node instanceof UnaryOpNode             => $this->evalUnary($node, $scope),
            $node instanceof TernaryNode             => $this->evalTernary($node, $scope),
            $node instanceof GatekeeperNode          => $this->evalGatekeeper($node, $scope),
            $node instanceof NullCoalesceNode        => $this->evalNullCoalesce($node, $scope),
            $node instanceof ModifierChainNode       => $this->evalModifierChain($node, $scope),
            $node instanceof CollectionOperationNode => $this->evalCollectionOperation($node, $scope),
            default                                  => throw new AntlersRuntimeException(
                'Cannot evaluate node of type: ' . $node::class,
            ),
        };
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function evaluateResult(AbstractNode $node, array $scope): ValueResult
    {
        return new ValueResult($this->evaluate($node, $scope));
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function evaluateTruthy(AbstractNode $node, array $scope): bool
    {
        return $this->isTruthy($this->evaluate($node, $scope));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveVariable(string $path, array $scope): mixed
    {
        if ($this->strict && ! $this->paths->has($path, $scope)) {
            throw new AntlersRuntimeException("Undefined variable: \"$path\"");
        }

        return $this->paths->get($path, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalString(StringValueNode $node, array $scope): string
    {
        if (! $node->hasInterpolations) {
            return $node->value;
        }

        $result = '';
        foreach ($node->parts as $part) {
            if (is_string($part)) {
                $result .= $part;
            } else {
                $result .= $this->stringify($this->evaluate($part, $scope));
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalBinary(BinaryOpNode $node, array $scope): mixed
    {
        $op = $node->operator;

        // Short-circuit logical operators
        $left = $this->evaluateResult($node->left, $scope);
        if ($op === '&&' || $op === 'and') {
            if (! $this->isTruthy($left->value)) {
                return false;
            }

            return $this->evaluateTruthy($node->right, $scope);
        }

        if ($op === '||' || $op === 'or') {
            if ($this->isTruthy($left->value)) {
                return true;
            }

            return $this->evaluateTruthy($node->right, $scope);
        }

        $right = $this->evaluateResult($node->right, $scope);

        $leftNumeric  = $this->coerceNumeric($left->value);
        $rightNumeric = $this->coerceNumeric($right->value);

        return match ($op) {
            '+'     => (float) $leftNumeric + (float) $rightNumeric,
            '-'     => (float) $leftNumeric - (float) $rightNumeric,
            '*'     => (float) $leftNumeric * (float) $rightNumeric,
            '/'     => $rightNumeric != 0
                        ? (float) $leftNumeric / (float) $rightNumeric
                        : throw new AntlersRuntimeException('Division by zero'),
            '%'     => $rightNumeric != 0
                        ? fmod((float) $leftNumeric, (float) $rightNumeric)
                        : throw new AntlersRuntimeException('Modulo by zero'),
            '^'     => $this->power((float) $leftNumeric, (float) $rightNumeric),
            '.'     => $this->stringify($left->value) . $this->stringify($right->value),
            '=='    => $left->value == $right->value,
            '!='    => $left->value != $right->value,
            '==='   => $left->value === $right->value,
            '!=='   => $left->value !== $right->value,
            '<'     => $left->value < $right->value,
            '>'     => $left->value > $right->value,
            '<='    => $left->value <= $right->value,
            '>='    => $left->value >= $right->value,
            default => throw new AntlersRuntimeException("Unknown binary operator: $op"),
        };
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalUnary(UnaryOpNode $node, array $scope): int|bool|float
    {
        $value = $this->evaluateResult($node->operand, $scope);

        return match ($node->operator) {
            '!', 'not' => ! $this->isTruthy($value->value),
            '-'        => -$this->coerceNumeric($value->value),
            default    => throw new AntlersRuntimeException("Unknown unary operator: $node->operator"),
        };
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalTernary(TernaryNode $node, array $scope): mixed
    {
        $cond = $this->evaluateResult($node->condition, $scope);

        return $this->isTruthy($cond->value)
            ? $this->evaluate($node->trueBranch, $scope)
            : $this->evaluate($node->falseBranch, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalGatekeeper(GatekeeperNode $node, array $scope): mixed
    {
        $condition = $this->evaluateResult($node->condition, $scope);

        if (! $this->isTruthy($condition->value)) {
            return null;
        }

        return $this->evaluate($node->right, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalNullCoalesce(NullCoalesceNode $node, array $scope): mixed
    {
        // Temporarily disable strict for the left-hand side — ?? is an explicit safe-access
        $prev = $this->strict;

        $this->strict = false;

        $left = $this->evaluateResult($node->left, $scope);

        $this->strict = $prev;

        return $left->value ?? $this->evaluate($node->right, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalModifierChain(ModifierChainNode $node, array $scope): mixed
    {
        $value = $this->evaluateResult($node->value, $scope);

        foreach ($node->modifiers as $modifier) {
            $params = array_map(
                fn(AbstractNode $p): mixed => $this->evaluate($p, $scope),
                $modifier->params,
            );

            $value = new ValueResult(
                $this->modifiers->apply($modifier->name, $value->value, $params, $scope),
            );
        }

        return $value->value;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalCollectionOperation(CollectionOperationNode $node, array $scope): mixed
    {
        return array_reduce(
            $node->operators,
            fn(mixed $carry, CollectionOperatorNode $operator): mixed => $this->applyCollectionOperator(
                $operator,
                $carry,
                $scope,
            ),
            $this->evaluate($node->value, $scope),
        );
    }

    public function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }

        if (in_array($value, ['', '0', 0, 0.0], true)) {
            return false;
        }

        return ! (is_array($value) && count($value) === 0);
    }

    public function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode('', array_map($this->stringify(...), $value));
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return '';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        return '';
    }

    private function coerceNumeric(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return 0;
    }

    private function power(float $left, float $right): float
    {
        return $left ** $right;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyCollectionOperator(CollectionOperatorNode $operator, mixed $value, array $scope): mixed
    {
        return match ($operator->name) {
            'merge'   => $this->applyMergeOperator($value, $operator, $scope),
            'where'   => $this->applyWhereOperator($value, $operator, $scope),
            'take'    => $this->applySliceOperator($value, $operator, $scope, true),
            'skip'    => $this->applySliceOperator($value, $operator, $scope, false),
            'pluck'   => $this->applyPluckOperator($value, $operator, $scope),
            'orderby' => $this->applyOrderByOperator($value, $operator, $scope),
            'groupby' => $this->applyGroupByOperator($value, $operator, $scope),
            default   => throw new AntlersRuntimeException("Unknown collection operator: $operator->name"),
        };
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyMergeOperator(mixed $value, CollectionOperatorNode $operator, array $scope): mixed
    {
        $left  = $this->iterableToArray($value);
        $right = $this->iterableToArray($this->evaluate($this->collectionExpressionArgument($operator), $scope));

        if ($left === null || $right === null) {
            return $value;
        }

        return array_merge($left, $right);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyWhereOperator(mixed $value, CollectionOperatorNode $operator, array $scope): mixed
    {
        $items = $this->iterableToArray($value);
        if ($items === null) {
            return $value;
        }

        $condition = $this->collectionExpressionArgument($operator);

        return array_values(array_filter($items, function (mixed $item) use ($condition, $operator, $scope): bool {
            $itemScope = $this->makeCollectionItemScope($scope, $item);

            if ($operator->scopeAlias !== null) {
                $itemScope = array_merge($itemScope, [$operator->scopeAlias => $item]);
            }

            return $this->evaluateTruthy($condition, $itemScope);
        }));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applySliceOperator(
        mixed $value,
        CollectionOperatorNode $operator,
        array $scope,
        bool $fromStart,
    ): mixed {
        $items = $this->iterableToArray($value);
        if ($items === null) {
            return $value;
        }

        $count = max(
            0,
            (int) $this->coerceNumeric($this->evaluate($this->collectionExpressionArgument($operator), $scope)),
        );

        return $fromStart ? array_slice($items, 0, $count) : array_slice($items, $count);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyPluckOperator(mixed $value, CollectionOperatorNode $operator, array $scope): mixed
    {
        $items = $this->iterableToArray($value);
        if ($items === null) {
            return $value;
        }

        return array_map(
            fn(mixed $item): mixed => $this->evaluateCollectionField(
                $this->collectionExpressionArgument($operator),
                $item,
                $scope,
            ),
            $items,
        );
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyOrderByOperator(mixed $value, CollectionOperatorNode $operator, array $scope): mixed
    {
        $items = $this->iterableToArray($value);
        if ($items === null) {
            return $value;
        }

        usort($items, function (mixed $left, mixed $right) use ($operator, $scope): int {
            foreach ($operator->arguments as $argument) {
                if (! $argument instanceof CollectionSortArgument) {
                    continue;
                }

                $direction = $this->sortDirection($argument->direction, $scope);
                $result    = $this->compareSortValues(
                    $this->evaluateCollectionField($argument->field, $left, $scope),
                    $this->evaluateCollectionField($argument->field, $right, $scope),
                );

                if ($result !== 0) {
                    return $direction === 'desc' ? -$result : $result;
                }
            }

            return 0;
        });

        return $items;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyGroupByOperator(mixed $value, CollectionOperatorNode $operator, array $scope): mixed
    {
        $items = $this->iterableToArray($value);
        if ($items === null) {
            return $value;
        }

        /** @var array<string, array{key: mixed, fields: array<string, mixed>, values: list<mixed>}> $groups */
        $groups = [];

        array_walk($items, function (mixed $item) use (&$groups, $operator, $scope): void {
            $groups = $this->reduceGroupedItems($groups, $item, $operator, $scope);
        });

        $valuesAlias = $operator->valuesAlias ?? 'values';

        return array_values(array_map(function (array $group) use ($valuesAlias): array {
            /** @var array<string, mixed> $base */
            $base = $group['fields'];
            $base = array_merge($base, ['key' => $group['key']]);

            $base[$valuesAlias] = $group['values'];

            if ($valuesAlias !== 'values') {
                $base['values'] = $group['values'];
            }

            return $base;
        }, $groups));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evaluateCollectionField(AbstractNode $field, mixed $item, array $scope): mixed
    {
        if ($field instanceof StringValueNode && ! $field->hasInterpolations) {
            return $this->paths->get($field->value, $this->makeCollectionItemScope($scope, $item));
        }

        return $this->evaluate($field, $this->makeCollectionItemScope($scope, $item));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function makeCollectionItemScope(array $scope, mixed $item): array
    {
        if (is_array($item)) {
            /** @var array<string, mixed> $normalized */
            $normalized = array_filter($item, is_string(...), ARRAY_FILTER_USE_KEY);

            return array_merge($scope, $normalized);
        }

        if (is_object($item)) {
            /** @var array<string, mixed> $normalized */
            $normalized = array_filter((array) $item, is_string(...), ARRAY_FILTER_USE_KEY);

            return array_merge($scope, $normalized);
        }

        return array_merge($scope, ['value' => $item]);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function iterableToArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function sortDirection(?AbstractNode $direction, array $scope): string
    {
        if ($direction === null) {
            return 'asc';
        }

        return $this->normalizeSortDirection($this->evaluate($direction, $scope));
    }

    private function compareSortValues(mixed $left, mixed $right): int
    {
        return $this->sortableValue($left) <=> $this->sortableValue($right);
    }

    private function sortableValue(mixed $value): int|float|string
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $this->stringify($value);
    }

    private function inferCollectionFieldAlias(AbstractNode $field): string
    {
        return match (true) {
            $field instanceof VariableNode => preg_replace('/^.*[.:]/', '', $field->path) ?? $field->path,
            $field instanceof StringValueNode && ! $field->hasInterpolations => $field->value,
            default => 'group',
        };
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildCollectionGroupKey(CollectionOperatorNode $operator, mixed $item, array $scope): array
    {
        /** @var array<string, mixed> $groupKey */
        $groupKey = [];

        foreach ($operator->arguments as $argument) {
            if (! $argument instanceof CollectionGroupArgument) {
                continue;
            }

            $groupName = $argument->alias ?? $this->inferCollectionFieldAlias($argument->field);
            $groupKey  = array_merge($groupKey, [
                $groupName => $this->evaluateCollectionField($argument->field, $item, $scope),
            ]);
        }

        return $groupKey;
    }

    /**
     * @param array<string, mixed> $groupKey
     * @param array{key: mixed, fields: array<string, mixed>, values: list<mixed>}|null $group
     * @return array{key: mixed, fields: array<string, mixed>, values: list<mixed>}
     */
    private function appendGroupedItem(?array $group, array $groupKey, mixed $item): array
    {
        if ($group === null) {
            $group = [
                'key'    => count($groupKey) === 1 ? reset($groupKey) : $groupKey,
                'fields' => $groupKey,
                'values' => [],
            ];
        }

        $group['values'] = array_merge($group['values'], [$item]);

        return $group;
    }

    /**
     * @param array<string, array{key: mixed, fields: array<string, mixed>, values: list<mixed>}> $groups
     * @return array{key: mixed, fields: array<string, mixed>, values: list<mixed>}|null
     */
    private function existingGroupedItem(array $groups, string $serialized): ?array
    {
        return $groups[$serialized] ?? null;
    }

    /**
     * @param array<string, array{key: mixed, fields: array<string, mixed>, values: list<mixed>}> $groups
     * @param array<string, mixed> $scope
     * @return array<string, array{key: mixed, fields: array<string, mixed>, values: list<mixed>}>
     */
    private function reduceGroupedItems(
        array $groups,
        mixed $item,
        CollectionOperatorNode $operator,
        array $scope,
    ): array {
        $groupKey   = $this->buildCollectionGroupKey($operator, $item, $scope);
        $serialized = serialize($groupKey);

        $groups[$serialized] = $this->appendGroupedItem(
            $this->existingGroupedItem($groups, $serialized),
            $groupKey,
            $item,
        );

        return $groups;
    }

    private function normalizeSortDirection(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'asc' : 'desc';
        }

        return strtolower($this->stringify($value)) === 'desc' ? 'desc' : 'asc';
    }

    private function collectionExpressionArgument(CollectionOperatorNode $operator): AbstractNode
    {
        $argument = $operator->arguments[0] ?? null;

        if ($argument instanceof AbstractNode) {
            return $argument;
        }

        throw new AntlersRuntimeException(
            "Collection operator \"$operator->name\" expects an expression argument",
        );
    }
}
