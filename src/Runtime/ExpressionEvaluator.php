<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\ArrayNode;
use Bugo\Antlers\Nodes\AssignmentNode;
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
use Bugo\Antlers\Nodes\SequenceNode;
use Bugo\Antlers\Nodes\StringValueNode;
use Bugo\Antlers\Nodes\TagSubExpressionNode;
use Bugo\Antlers\Nodes\TernaryNode;
use Bugo\Antlers\Nodes\TruthyCoalesceNode;
use Bugo\Antlers\Nodes\UnaryOpNode;
use Bugo\Antlers\Nodes\VariableNode;
use Bugo\Antlers\Nodes\VoidNode;

/**
 * Evaluates expression AST nodes against a data scope.
 */
final class ExpressionEvaluator
{
    private ?NodeProcessor $processor = null;

    public function __construct(
        private readonly PathDataManager $paths,
        private readonly ModifierRunner $modifiers,
        private readonly RuntimeOptions $options,
    ) {}

    public function setProcessor(NodeProcessor $processor): void
    {
        $this->processor = $processor;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function evaluate(AbstractNode $node, array $scope, ?callable $assignmentWriter = null): mixed
    {
        return match (true) {
            $node instanceof NumberNode              => $node->value,
            $node instanceof BooleanNode             => $node->value,
            $node instanceof NullNode                => null,
            $node instanceof VoidNode                => VoidValue::instance(),
            $node instanceof ArrayNode               => $this->evalArray($node, $scope, $assignmentWriter),
            $node instanceof StringValueNode         => $this->evalString($node, $scope),
            $node instanceof VariableNode            => $this->resolveVariable($node->path, $scope),
            $node instanceof AssignmentNode          => $this->evalAssignment($node, $scope, $assignmentWriter),
            $node instanceof SequenceNode            => $this->evalSequence($node, $scope, $assignmentWriter),
            $node instanceof BinaryOpNode            => $this->evalBinary($node, $scope, $assignmentWriter),
            $node instanceof UnaryOpNode             => $this->evalUnary($node, $scope, $assignmentWriter),
            $node instanceof TernaryNode             => $this->evalTernary($node, $scope, $assignmentWriter),
            $node instanceof GatekeeperNode          => $this->evalGatekeeper($node, $scope, $assignmentWriter),
            $node instanceof NullCoalesceNode        => $this->evalNullCoalesce($node, $scope, $assignmentWriter),
            $node instanceof TruthyCoalesceNode      => $this->evalTruthyCoalesce($node, $scope, $assignmentWriter),
            $node instanceof ModifierChainNode       => $this->evalModifierChain($node, $scope, $assignmentWriter),
            $node instanceof CollectionOperationNode => $this->evalCollectionOperation($node, $scope, $assignmentWriter),
            $node instanceof TagSubExpressionNode    => $this->evalTagSubExpression($node, $scope),
            default                                  => throw new AntlersRuntimeException(
                'Cannot evaluate node of type: ' . $node::class,
            ),
        };
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function evaluateResult(AbstractNode $node, array $scope, ?callable $assignmentWriter = null): ValueResult
    {
        return new ValueResult($this->evaluate($node, $scope, $assignmentWriter));
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function evaluateTruthy(AbstractNode $node, array $scope, ?callable $assignmentWriter = null): bool
    {
        return $this->isTruthy($this->evaluate($node, $scope, $assignmentWriter));
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function resolveVariable(string $path, array $scope): mixed
    {
        if ($this->options->guardPolicy->guardsVariable($path)) {
            return $this->options->fail(sprintf('Guarded variable: "%s"', $path), null);
        }

        if ($this->options->strict && ! $this->paths->has($path, $scope)) {
            throw new AntlersRuntimeException(sprintf('Undefined variable: "%s"', $path));
        }

        return $this->options->guardPolicy->redact($path, $this->paths->get($path, $scope));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalString(StringValueNode $node, array $scope): mixed
    {
        if (! $node->hasInterpolations) {
            return $node->value;
        }

        if (count($node->parts) === 1 && ! is_string($node->parts[0])) {
            return $this->evaluate($node->parts[0], $scope);
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
    private function evalBinary(BinaryOpNode $node, array $scope, ?callable $assignmentWriter = null): mixed
    {
        $op = $node->operator;

        // Short-circuit logical operators
        $left = $this->evaluateResult($node->left, $scope, $assignmentWriter);
        if ($op === '&&' || $op === 'and') {
            if (! $this->isTruthy($left->value)) {
                return false;
            }

            return $this->evaluateTruthy($node->right, $scope, $assignmentWriter);
        }

        if ($op === '||' || $op === 'or') {
            if ($this->isTruthy($left->value)) {
                return true;
            }

            return $this->evaluateTruthy($node->right, $scope, $assignmentWriter);
        }

        $right = $this->evaluateResult($node->right, $scope, $assignmentWriter);

        if ($op === 'xor') {
            return $this->isTruthy($left->value) xor $this->isTruthy($right->value);
        }

        $leftNumeric  = $this->coerceNumeric($left->value);
        $rightNumeric = $this->coerceNumeric($right->value);

        return match ($op) {
            '+'     => ValueCoercion::add($leftNumeric, $rightNumeric),
            '-'     => ValueCoercion::subtract($leftNumeric, $rightNumeric),
            '*'     => ValueCoercion::multiply($leftNumeric, $rightNumeric),
            '/'     => $rightNumeric != 0
                        ? ValueCoercion::divide($leftNumeric, $rightNumeric)
                        : throw new AntlersRuntimeException('Division by zero'),
            '%'     => $rightNumeric != 0
                        ? ValueCoercion::modulo($leftNumeric, $rightNumeric)
                        : throw new AntlersRuntimeException('Modulo by zero'),
            '**',
            '^'     => ValueCoercion::power($leftNumeric, $rightNumeric),
            '.'     => $this->stringify($left->value) . $this->stringify($right->value),
            '<=>'   => ValueCoercion::compare($left->value, $right->value),
            '=='    => $left->value == $right->value,
            '!='    => $left->value != $right->value,
            '==='   => $left->value === $right->value,
            '!=='   => $left->value !== $right->value,
            '<'     => ValueCoercion::compare($left->value, $right->value) < 0,
            '>'     => ValueCoercion::compare($left->value, $right->value) > 0,
            '<='    => ValueCoercion::compare($left->value, $right->value) <= 0,
            '>='    => ValueCoercion::compare($left->value, $right->value) >= 0,
            default => throw new AntlersRuntimeException('Unknown binary operator: ' . $op),
        };
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<mixed>
     */
    private function evalArray(ArrayNode $node, array $scope, ?callable $assignmentWriter = null): array
    {
        return array_map(
            fn(AbstractNode $item): mixed => $this->evaluate($item, $scope, $assignmentWriter),
            $node->items,
        );
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalUnary(UnaryOpNode $node, array $scope, ?callable $assignmentWriter = null): int|bool|float
    {
        $value = $this->evaluateResult($node->operand, $scope, $assignmentWriter);

        return match ($node->operator) {
            '!', 'not' => ! $this->isTruthy($value->value),
            '-'        => -$this->coerceNumeric($value->value),
            default    => throw new AntlersRuntimeException('Unknown unary operator: ' . $node->operator),
        };
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalTernary(TernaryNode $node, array $scope, ?callable $assignmentWriter = null): mixed
    {
        $cond = $this->evaluateResult($node->condition, $scope, $assignmentWriter);

        return $this->isTruthy($cond->value)
            ? $this->evaluate($node->trueBranch, $scope, $assignmentWriter)
            : $this->evaluate($node->falseBranch, $scope, $assignmentWriter);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalGatekeeper(GatekeeperNode $node, array $scope, ?callable $assignmentWriter = null): mixed
    {
        $condition = $this->evaluateResult($node->condition, $scope, $assignmentWriter);

        if (! $this->isTruthy($condition->value)) {
            return null;
        }

        return $this->evaluate($node->right, $scope, $assignmentWriter);
    }

    /**
     * `??` — falls back whenever the left side is falsy, using the same
     * truthiness rules as {{ if }}.
     *
     * @param array<string, mixed> $scope
     */
    private function evalTruthyCoalesce(TruthyCoalesceNode $node, array $scope, ?callable $assignmentWriter = null): mixed
    {
        $left = $this->evaluateOptional($node->left, $scope, $assignmentWriter);

        return $this->isTruthy($left->value)
            ? $left->value
            : $this->evaluate($node->right, $scope, $assignmentWriter);
    }

    /**
     * `???` — falls back only on null, so 0, false and '' survive.
     *
     * @param array<string, mixed> $scope
     */
    private function evalNullCoalesce(NullCoalesceNode $node, array $scope, ?callable $assignmentWriter = null): mixed
    {
        return $this->evaluateOptional($node->left, $scope, $assignmentWriter)->value
            ?? $this->evaluate($node->right, $scope, $assignmentWriter);
    }

    /**
     * Evaluates the left side of a coalescing operator. Both operators are an
     * explicit "use it if it is there", so an undefined variable must not throw
     * even in strict mode.
     *
     * @param array<string, mixed> $scope
     */
    private function evaluateOptional(AbstractNode $node, array $scope, ?callable $assignmentWriter = null): ValueResult
    {
        return $this->options->withoutStrict(
            fn(): ValueResult => $this->evaluateResult($node, $scope, $assignmentWriter),
        );
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalModifierChain(ModifierChainNode $node, array $scope, ?callable $assignmentWriter = null): mixed
    {
        $value = $this->evaluateResult($node->value, $scope, $assignmentWriter);

        foreach ($node->modifiers as $modifier) {
            $params = array_map(
                fn(AbstractNode $p): mixed => $this->evaluate($p, $scope, $assignmentWriter),
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
    private function evalCollectionOperation(
        CollectionOperationNode $node,
        array $scope,
        ?callable $assignmentWriter = null,
    ): mixed {
        return array_reduce(
            $node->operators,
            fn(mixed $carry, CollectionOperatorNode $operator): mixed => $this->applyCollectionOperator(
                $operator,
                $carry,
                $scope,
                $assignmentWriter,
            ),
            $this->evaluate($node->value, $scope, $assignmentWriter),
        );
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalAssignment(AssignmentNode $node, array $scope, ?callable $assignmentWriter = null): mixed
    {
        $result = $this->evaluateResult($node->value, $scope, $assignmentWriter);

        if ($assignmentWriter !== null) {
            $assignmentWriter($node->variableName, $result->value);
        }

        return $result->value;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalSequence(SequenceNode $node, array $scope, ?callable $assignmentWriter = null): mixed
    {
        $sequenceScope = $scope;
        $lastResult    = new ValueResult(null);

        $sequenceWriter = function (string $name, mixed $value) use (&$sequenceScope, $assignmentWriter): void {
            $sequenceScope = array_merge($sequenceScope, [$name => $value]);

            if ($assignmentWriter !== null) {
                $assignmentWriter($name, $value);
            }
        };

        foreach ($node->statements as $statement) {
            $lastResult = $this->evaluateResult($statement, $sequenceScope, $sequenceWriter);
        }

        return $lastResult->value;
    }

    public function isTruthy(mixed $value): bool
    {
        if ($value instanceof VoidValue) {
            return false;
        }

        if ($value === null || $value === false) {
            return false;
        }

        if (in_array($value, ['', '0', 0, 0.0], true)) {
            return false;
        }

        return $value !== [];
    }

    public function stringify(mixed $value): string
    {
        return ValueCoercion::toString($value);
    }

    private function coerceNumeric(mixed $value): int|float
    {
        return ValueCoercion::toNumber($value);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyCollectionOperator(
        CollectionOperatorNode $operator,
        mixed $value,
        array $scope,
        ?callable $assignmentWriter = null,
    ): mixed {
        return match ($operator->name) {
            'merge'   => $this->applyMergeOperator($value, $operator, $scope, $assignmentWriter),
            'where'   => $this->applyWhereOperator($value, $operator, $scope, $assignmentWriter),
            'take'    => $this->applySliceOperator($value, $operator, $scope, true, $assignmentWriter),
            'skip'    => $this->applySliceOperator($value, $operator, $scope, false, $assignmentWriter),
            'pluck'   => $this->applyPluckOperator($value, $operator, $scope, $assignmentWriter),
            'orderby' => $this->applyOrderByOperator($value, $operator, $scope, $assignmentWriter),
            'groupby' => $this->applyGroupByOperator($value, $operator, $scope, $assignmentWriter),
            default   => throw new AntlersRuntimeException('Unknown collection operator: ' . $operator->name),
        };
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyMergeOperator(
        mixed $value,
        CollectionOperatorNode $operator,
        array $scope,
        ?callable $assignmentWriter = null,
    ): mixed {
        $left  = $this->iterableToArray($value);
        $right = $this->iterableToArray(
            $this->evaluate($this->collectionExpressionArgument($operator), $scope, $assignmentWriter),
        );

        if ($left === null || $right === null) {
            return $value;
        }

        return array_merge($left, $right);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyWhereOperator(
        mixed $value,
        CollectionOperatorNode $operator,
        array $scope,
        ?callable $assignmentWriter = null,
    ): mixed {
        $items = $this->iterableToArray($value);
        if ($items === null) {
            return $value;
        }

        $condition = $this->collectionExpressionArgument($operator);

        return array_values(array_filter($items, function (mixed $item) use ($condition, $operator, $scope, $assignmentWriter): bool {
            $itemScope = $this->makeCollectionItemScope($scope, $item);

            if ($operator->scopeAlias !== null) {
                $itemScope = array_merge($itemScope, [$operator->scopeAlias => $item]);
            }

            return $this->evaluateTruthy($condition, $itemScope, $assignmentWriter);
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
        ?callable $assignmentWriter = null,
    ): mixed {
        $items = $this->iterableToArray($value);
        if ($items === null) {
            return $value;
        }

        $count = max(
            0,
            (int) $this->coerceNumeric(
                $this->evaluate($this->collectionExpressionArgument($operator), $scope, $assignmentWriter),
            ),
        );

        return $fromStart ? array_slice($items, 0, $count) : array_slice($items, $count);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyPluckOperator(
        mixed $value,
        CollectionOperatorNode $operator,
        array $scope,
        ?callable $assignmentWriter = null,
    ): mixed {
        $items = $this->iterableToArray($value);
        if ($items === null) {
            return $value;
        }

        return array_map(
            fn(mixed $item): mixed => $this->evaluateCollectionField(
                $this->collectionExpressionArgument($operator),
                $item,
                $scope,
                $assignmentWriter,
            ),
            $items,
        );
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyOrderByOperator(
        mixed $value,
        CollectionOperatorNode $operator,
        array $scope,
        ?callable $assignmentWriter = null,
    ): mixed {
        $items = $this->iterableToArray($value);
        if ($items === null) {
            return $value;
        }

        usort($items, function (mixed $left, mixed $right) use ($operator, $scope, $assignmentWriter): int {
            foreach ($operator->arguments as $argument) {
                if (! $argument instanceof CollectionSortArgument) {
                    continue;
                }

                $direction = $this->sortDirection($argument->direction, $scope, $assignmentWriter);

                $result = ValueCoercion::compare(
                    $this->evaluateCollectionField($argument->field, $left, $scope, $assignmentWriter),
                    $this->evaluateCollectionField($argument->field, $right, $scope, $assignmentWriter),
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
    private function applyGroupByOperator(
        mixed $value,
        CollectionOperatorNode $operator,
        array $scope,
        ?callable $assignmentWriter = null,
    ): mixed {
        $items = $this->iterableToArray($value);
        if ($items === null) {
            return $value;
        }

        $items = array_values($items);

        /** @var array<string, array{key: mixed, fields: array<string, mixed>, indexes: list<int>}> $groups */
        $groups = [];

        foreach (array_keys($items) as $index) {
            $groupKey   = $this->buildCollectionGroupKey($operator, $items[$index], $scope, $assignmentWriter);
            $serialized = serialize($groupKey);

            $groups[$serialized] ??= [
                'key'     => count($groupKey) === 1 ? reset($groupKey) : $groupKey,
                'fields'  => $groupKey,
                'indexes' => [],
            ];

            $groups[$serialized]['indexes'][] = $index;
        }

        $itemsAlias = $operator->valuesAlias ?? 'items';

        return array_values(array_map(function (array $group) use ($items, $itemsAlias): array {
            $groupItems = array_map(static fn(int $index): mixed => $items[$index], $group['indexes']);

            /** @var array<string, mixed> $base */
            $base = array_merge($group['fields'], ['key' => $group['key'], 'group' => $group['key']]);

            $base[$itemsAlias] = $groupItems;

            if ($itemsAlias !== 'items') {
                $base['items'] = $groupItems;
            }

            return $base;
        }, $groups));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evaluateCollectionField(
        AbstractNode $field,
        mixed $item,
        array $scope,
        ?callable $assignmentWriter = null,
    ): mixed {
        if ($field instanceof StringValueNode && ! $field->hasInterpolations) {
            return $this->paths->get($field->value, $this->makeCollectionItemScope($scope, $item));
        }

        return $this->evaluate($field, $this->makeCollectionItemScope($scope, $item), $assignmentWriter);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function makeCollectionItemScope(array $scope, mixed $item): array
    {
        return array_merge($scope, ValueCoercion::toScopeFrame($item) ?? ['value' => $item]);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function iterableToArray(mixed $value): ?array
    {
        return ValueCoercion::toArray($value);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function sortDirection(
        ?AbstractNode $direction,
        array $scope,
        ?callable $assignmentWriter = null,
    ): string {
        if (! $direction instanceof AbstractNode) {
            return 'asc';
        }

        return $this->normalizeSortDirection($this->evaluate($direction, $scope, $assignmentWriter));
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
    private function buildCollectionGroupKey(
        CollectionOperatorNode $operator,
        mixed $item,
        array $scope,
        ?callable $assignmentWriter = null,
    ): array {
        /** @var array<string, mixed> $groupKey */
        $groupKey = [];

        foreach ($operator->arguments as $argument) {
            if (! $argument instanceof CollectionGroupArgument) {
                continue;
            }

            $groupName = $argument->alias ?? $this->inferCollectionFieldAlias($argument->field);
            $groupKey  = array_merge($groupKey, [
                $groupName => $this->evaluateCollectionField($argument->field, $item, $scope, $assignmentWriter),
            ]);
        }

        return $groupKey;
    }

    private function normalizeSortDirection(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'asc' : 'desc';
        }

        return strtolower($this->stringify($value)) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function evalTagSubExpression(TagSubExpressionNode $node, array $scope): mixed
    {
        if (! $this->processor instanceof NodeProcessor) {
            throw new AntlersRuntimeException('NodeProcessor not set for tag sub-expression evaluation');
        }

        return $this->processor->callTag($node->tag, $scope);
    }

    private function collectionExpressionArgument(CollectionOperatorNode $operator): AbstractNode
    {
        $argument = $operator->arguments[0] ?? null;

        if ($argument instanceof AbstractNode) {
            return $argument;
        }

        throw new AntlersRuntimeException(
            sprintf('Collection operator "%s" expects an expression argument', $operator->name),
        );
    }
}
