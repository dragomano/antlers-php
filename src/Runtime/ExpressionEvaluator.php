<?php

declare(strict_types=1);

namespace Bugo\Antlers\Runtime;

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\BinaryOpNode;
use Bugo\Antlers\Nodes\BooleanNode;
use Bugo\Antlers\Nodes\GatekeeperNode;
use Bugo\Antlers\Nodes\ModifierChainNode;
use Bugo\Antlers\Nodes\NullCoalesceNode;
use Bugo\Antlers\Nodes\NullNode;
use Bugo\Antlers\Nodes\NumberNode;
use Bugo\Antlers\Nodes\StringValueNode;
use Bugo\Antlers\Nodes\TernaryNode;
use Bugo\Antlers\Nodes\UnaryOpNode;
use Bugo\Antlers\Nodes\VariableNode;

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
            $node instanceof NumberNode        => $node->value,
            $node instanceof BooleanNode       => $node->value,
            $node instanceof NullNode          => null,
            $node instanceof StringValueNode   => $this->evalString($node, $scope),
            $node instanceof VariableNode      => $this->resolveVariable($node->path, $scope),
            $node instanceof BinaryOpNode      => $this->evalBinary($node, $scope),
            $node instanceof UnaryOpNode       => $this->evalUnary($node, $scope),
            $node instanceof TernaryNode       => $this->evalTernary($node, $scope),
            $node instanceof GatekeeperNode    => $this->evalGatekeeper($node, $scope),
            $node instanceof NullCoalesceNode  => $this->evalNullCoalesce($node, $scope),
            $node instanceof ModifierChainNode => $this->evalModifierChain($node, $scope),
            default                            => throw new AntlersRuntimeException(
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
}
