<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\GuardPolicy;
use Bugo\Antlers\Modifiers\ModifierRegistry;
use Bugo\Antlers\Nodes\BinaryOpNode;
use Bugo\Antlers\Nodes\BooleanNode;
use Bugo\Antlers\Nodes\CollectionGroupArgument;
use Bugo\Antlers\Nodes\CollectionOperationNode;
use Bugo\Antlers\Nodes\CollectionOperatorNode;
use Bugo\Antlers\Nodes\CollectionSortArgument;
use Bugo\Antlers\Nodes\NumberNode;
use Bugo\Antlers\Nodes\StringValueNode;
use Bugo\Antlers\Nodes\UnaryOpNode;
use Bugo\Antlers\Nodes\VariableNode;
use Bugo\Antlers\Runtime\ExpressionEvaluator;
use Bugo\Antlers\Runtime\ModifierRunner;
use Bugo\Antlers\Runtime\PathDataManager;
use Bugo\Antlers\Runtime\RuntimeOptions;
use Bugo\Antlers\Runtime\VoidValue;

function expressionEvaluator(bool $strict = false, ?GuardPolicy $guardPolicy = null): ExpressionEvaluator
{
    $options              = new RuntimeOptions();
    $options->strict      = $strict;
    $options->guardPolicy = $guardPolicy ?? new GuardPolicy();

    return new ExpressionEvaluator(
        new PathDataManager(),
        new ModifierRunner(new ModifierRegistry(), $options),
        $options,
    );
}

describe('ExpressionEvaluator', function (): void {
    it('evaluates guarded variables, interpolated strings and short-circuit operators', function (): void {
        $guarded = expressionEvaluator(true, new GuardPolicy(variables: ['secret']));
        $strict  = expressionEvaluator(true);
        $plain   = expressionEvaluator();

        $string        = new StringValueNode('', true);
        $string->parts = ['Hello ', new VariableNode('name'), '!'];

        expect(fn(): mixed => $guarded->evaluate(new VariableNode('secret'), ['secret' => 'x']))
            ->toThrow(AntlersRuntimeException::class, 'Guarded variable: "secret"')
            ->and($plain->evaluate($string, ['name' => 'Bob']))->toBe('Hello Bob!')
            ->and($strict->evaluate(
                new BinaryOpNode(new BooleanNode(false), '&&', new VariableNode('missing')),
                [],
            ))->toBeFalse()
            ->and($strict->evaluate(
                new BinaryOpNode(new BooleanNode(true), '||', new VariableNode('missing')),
                [],
            ))->toBeTrue()
            ->and($plain->evaluate(new BinaryOpNode(new NumberNode(2), '^', new NumberNode(3)), []))->toBe(8.0)
            ->and($plain->evaluate(new UnaryOpNode('-', new NumberNode(5)), []))->toBe(-5);
    });

    it('handles truthiness and string conversion edge cases', function (): void {
        $evaluator = expressionEvaluator();
        $resource  = tmpfile();

        expect($evaluator->isTruthy(VoidValue::instance()))->toBeFalse()
            ->and($evaluator->isTruthy('0'))->toBeFalse()
            ->and($evaluator->stringify(VoidValue::instance()))->toBe('')
            ->and($evaluator->stringify(new stdClass()))->toBe('')
            ->and($evaluator->stringify($resource))->toBe('');

        fclose($resource);
    });

    it('returns the original value for collection operators applied to non-iterables and supports traversables', function (): void {
        $evaluator = expressionEvaluator();

        $merge = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('merge', [new VariableNode('extras')]),
        ]);
        $where = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('where', [new BooleanNode(true)]),
        ]);
        $take = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('take', [new NumberNode(1)]),
        ]);
        $pluck = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('pluck', [new StringValueNode('name')]),
        ]);
        $orderby = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('orderby', [new CollectionSortArgument(new StringValueNode('name'))]),
        ]);
        $groupby = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('groupby', [new CollectionGroupArgument(new StringValueNode('role'))]),
        ]);

        expect($evaluator->evaluate($merge, ['items' => [1], 'extras' => 'bad']))->toBe([1])
            ->and($evaluator->evaluate($where, ['items' => 'bad']))->toBe('bad')
            ->and($evaluator->evaluate($take, ['items' => 'bad']))->toBe('bad')
            ->and($evaluator->evaluate($pluck, ['items' => 'bad']))->toBe('bad')
            ->and($evaluator->evaluate($orderby, ['items' => 'bad']))->toBe('bad')
            ->and($evaluator->evaluate($groupby, ['items' => 'bad']))->toBe('bad')
            ->and($evaluator->evaluate($take, ['items' => new ArrayIterator([1, 2])]))->toBe([1]);
    });

    it('handles collection scopes, sorting and grouping helper branches', function (): void {
        $evaluator = expressionEvaluator();

        $pluckObject = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('pluck', [new StringValueNode('name')]),
        ]);
        $pluckScalar = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('pluck', [new VariableNode('value')]),
        ]);
        $whereAlias = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode(
                'where',
                [new BinaryOpNode(new VariableNode('entry.active'), '==', new BooleanNode(true))],
                null,
                'entry',
            ),
        ]);
        $sortBool = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('orderby', [new CollectionSortArgument(new StringValueNode('active'))]),
        ]);
        $sortObject = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('orderby', [
                new CollectionSortArgument(new StringValueNode('meta'), new StringValueNode('sideways')),
            ]),
        ]);
        $sortInvalid = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('orderby', [new StringValueNode('ignored')]),
        ]);
        $groupRole = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('groupby', [new CollectionGroupArgument(new StringValueNode('role'))]),
        ]);
        $groupInvalid = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('groupby', [new StringValueNode('ignored')]),
        ]);

        $objectA = new class ('b') {
            public function __construct(private readonly string $value) {}

            public function __toString(): string
            {
                return $this->value;
            }
        };
        $objectB = new class ('a') {
            public function __construct(private readonly string $value) {}

            public function __toString(): string
            {
                return $this->value;
            }
        };

        expect($evaluator->evaluate($pluckObject, [
            'items' => [(object) ['name' => 'Alice']],
        ]))->toBe(['Alice'])
            ->and($evaluator->evaluate($pluckScalar, ['items' => [1, 2]]))->toBe([1, 2])
            ->and($evaluator->evaluate($whereAlias, ['items' => [
                ['active' => true],
                ['active' => false],
            ]]))->toBe([['active' => true]])
            ->and($evaluator->evaluate($sortBool, ['items' => [
                ['name' => 'B', 'active' => true],
                ['name' => 'A', 'active' => false],
            ]]))->toBe([
                ['name' => 'A', 'active' => false],
                ['name' => 'B', 'active' => true],
            ])
            ->and($evaluator->evaluate($sortObject, ['items' => [
                ['meta' => $objectA],
                ['meta' => $objectB],
            ]]))->toBe([
                ['meta' => $objectB],
                ['meta' => $objectA],
            ])
            ->and($evaluator->evaluate($sortInvalid, ['items' => [1, 1]]))->toBe([1, 1])
            ->and($evaluator->evaluate($groupRole, ['items' => [
                ['role' => 'admin'],
                ['role' => 'editor'],
            ]]))->toBe([
                ['role' => 'admin', 'key' => 'admin', 'values' => [['role' => 'admin']]],
                ['role' => 'editor', 'key' => 'editor', 'values' => [['role' => 'editor']]],
            ])
            ->and($evaluator->evaluate($groupInvalid, ['items' => [1]]))->toBe([
                ['key' => [], 'values' => [1]],
            ]);
    });

    it('throws when a collection operator is missing its expression argument', function (): void {
        $evaluator = expressionEvaluator();
        $node      = new CollectionOperationNode(new VariableNode('items'), [
            new CollectionOperatorNode('take'),
        ]);

        expect(fn(): mixed => $evaluator->evaluate($node, ['items' => [1, 2, 3]]))
            ->toThrow(AntlersRuntimeException::class, 'Collection operator "take" expects an expression argument');
    });
});
