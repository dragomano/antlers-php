<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\GuardPolicy;
use Bugo\Antlers\Modifiers\ModifierRegistry;
use Bugo\Antlers\Nodes\AssignmentNode;
use Bugo\Antlers\Nodes\BinaryOpNode;
use Bugo\Antlers\Nodes\BooleanNode;
use Bugo\Antlers\Nodes\CollectionGroupArgument;
use Bugo\Antlers\Nodes\CollectionOperationNode;
use Bugo\Antlers\Nodes\CollectionOperatorNode;
use Bugo\Antlers\Nodes\CollectionSortArgument;
use Bugo\Antlers\Nodes\NullNode;
use Bugo\Antlers\Nodes\NumberNode;
use Bugo\Antlers\Nodes\StringValueNode;
use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Nodes\TagSubExpressionNode;
use Bugo\Antlers\Nodes\UnaryOpNode;
use Bugo\Antlers\Nodes\VariableNode;
use Bugo\Antlers\Nodes\VoidNode;
use Bugo\Antlers\Parser\DocumentParser;
use Bugo\Antlers\Parser\LanguageParser;
use Bugo\Antlers\Runtime\ConditionProcessor;
use Bugo\Antlers\Runtime\ExpressionEvaluator;
use Bugo\Antlers\Runtime\ModifierRunner;
use Bugo\Antlers\Runtime\NodeProcessor;
use Bugo\Antlers\Runtime\PathDataManager;
use Bugo\Antlers\Runtime\RuntimeOptions;
use Bugo\Antlers\Runtime\VoidValue;
use Bugo\Antlers\Tags\TagRegistry;

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
    it('requires a processor to evaluate tag subexpressions', function (): void {
        $node = new TagSubExpressionNode(new TagNode('example'));

        expect(fn(): mixed => expressionEvaluator()->evaluate($node, []))
            ->toThrow(AntlersRuntimeException::class, 'NodeProcessor not set for tag sub-expression evaluation');
    });

    describe('tag subexpressions', function (): void {
        beforeEach(function (): void {
            $this->tags    = new TagRegistry();
            $this->runtime = new RuntimeOptions();
            $paths         = new PathDataManager();

            $this->evaluator = new ExpressionEvaluator(
                $paths,
                new ModifierRunner(new ModifierRegistry(), $this->runtime),
                $this->runtime,
            );

            $this->processor = new NodeProcessor(
                new DocumentParser(),
                $this->evaluator,
                new ConditionProcessor($this->evaluator),
                $this->tags,
                $paths,
                new LanguageParser(),
                $this->runtime,
            );
        });

        it('preserves raw tag results and only stringifies ordinary tag output', function (mixed $value): void {
            $this->tags->register('result', static fn(): mixed => $value);

            $tag = new TagNode('result');

            expect($this->evaluator->evaluate(new TagSubExpressionNode($tag), []))->toBe($value)
                ->and($this->processor->reduce([$tag]))->toBe($this->evaluator->stringify($value));
        })->with([
            'array'       => [[['title' => 'First'], ['title' => 'Second']]],
            'empty array' => [[]],
            'true'        => [true],
            'false'       => [false],
            'null'        => [null],
        ]);

        it('shares parameter evaluation and the active processor with ordinary tags', function (): void {
            $this->processor->setGlobalData(['global' => 'visible']);

            $this->tags->register('nested', static fn(): array => ['raw' => true]);
            $this->tags->register('probe', function ($params, $data, $processor, $method, $children): bool {
                expect($params)->toBe([
                    'value' => 7,
                    'null' => null,
                    'false' => false,
                    'nested' => ['raw' => true],
                    'assigned' => 'saved',
                ])
                    ->and($data)->toBe(['global' => 'visible', 'input' => 7])
                    ->and($processor)->toBe($this->processor)
                    ->and($method)->toBe('inspect')
                    ->and($children)->toBe([]);

                $processor->storeSection('shared', 'section');

                return true;
            });

            $tag = new TagNode('probe', 'inspect', [
                'value'    => new VariableNode('input'),
                'void'     => new VoidNode(),
                'null'     => new NullNode(),
                'false'    => new BooleanNode(false),
                'nested'   => new TagSubExpressionNode(new TagNode('nested')),
                'assigned' => new AssignmentNode('written', new StringValueNode('saved')),
            ]);

            foreach ([$tag, new TagSubExpressionNode($tag)] as $node) {
                expect($this->processor->reduce([$node, new VariableNode('written')], ['input' => 7]))
                    ->toBe('truesaved')
                    ->and($this->processor->yieldSection('shared'))->toBe('section');
            }
        });

        it('shares unknown and guarded tag policies before evaluating parameters', function (bool $strict, bool $guarded): void {
            $this->runtime->strict = $strict;

            $calls = 0;

            $this->tags->register('parameter', function () use (&$calls): void {
                $calls++;
            });

            if ($guarded) {
                $this->runtime->guardPolicy = new GuardPolicy(tags: ['blocked']);
                $this->tags->register('blocked', function () use (&$calls): void {
                    $calls++;
                });
            }

            $tag = new TagNode('blocked', parameters: [
                'value' => new TagSubExpressionNode(new TagNode('parameter')),
            ]);

            $node    = new TagSubExpressionNode($tag);
            $message = $guarded ? 'Guarded tag: "blocked"' : 'Unknown tag: "blocked"';

            if ($strict) {
                expect(fn(): mixed => $this->evaluator->evaluate($node, []))
                    ->toThrow(AntlersRuntimeException::class, $message)
                    ->and(fn(): string => $this->processor->reduce([$tag]))
                    ->toThrow(AntlersRuntimeException::class, $message);
            } else {
                expect($this->evaluator->evaluate($node, []))->toBe('')
                    ->and($this->processor->reduce([$tag]))->toBe('');
            }

            expect($calls)->toBe(0);
        })->with([false, true])->with([false, true]);

        it('restores the outer tag context after nested subexpression failures', function (): void {
            $this->tags->register('inner', static function (): never {
                throw new AntlersRuntimeException('Inner failure');
            });

            $this->tags->register('outer', function ($params, $data, NodeProcessor $processor): string {
                expect(fn(): mixed => $this->evaluator->evaluate(
                    new TagSubExpressionNode(new TagNode('inner')),
                    $data,
                ))->toThrow(AntlersRuntimeException::class, 'Inner failure');

                return $processor->renderOnce(null, static fn(): string => 'once');
            });

            $first = new TagNode('outer');
            $first->line = 3;

            $second = new TagNode('outer');
            $second->line = 4;

            expect($this->processor->reduce([
                new TagSubExpressionNode($first),
                new TagSubExpressionNode($first),
                new TagSubExpressionNode($second),
            ]))->toBe('onceonce');
        });

        it('attaches the enclosing statement line to tag subexpression failures', function (): void {
            $this->runtime->strict = true;

            $node = new AssignmentNode('result', new TagSubExpressionNode(new TagNode('missing')));
            $node->line = 12;

            expect(fn(): string => $this->processor->reduce([$node]))
                ->toThrow(AntlersRuntimeException::class, 'Unknown tag: "missing" on line 12');
        });
    });
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
            ->and($plain->evaluate(new BinaryOpNode(new NumberNode(2), '^', new NumberNode(3)), []))->toBe(8)
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
                ['role' => 'admin', 'key' => 'admin', 'group' => 'admin', 'items' => [['role' => 'admin']]],
                ['role' => 'editor', 'key' => 'editor', 'group' => 'editor', 'items' => [['role' => 'editor']]],
            ])
            ->and($evaluator->evaluate($groupInvalid, ['items' => [1]]))->toBe([
                ['key' => [], 'group' => [], 'items' => [1]],
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
