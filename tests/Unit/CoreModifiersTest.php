<?php

declare(strict_types=1);

use Bugo\Antlers\Modifiers\CoreModifiers;
use Bugo\Antlers\Modifiers\ModifierInterface;
use Bugo\Antlers\Modifiers\ModifierRegistry;

function coreModifierRegistry(): ModifierRegistry
{
    $registry = new ModifierRegistry();
    CoreModifiers::register($registry);

    return $registry;
}

it('registers the standalone mandatory modifiers from the spec', function (): void {
    $registry = coreModifierRegistry();

    $requiredModifiers = [
        'upper',
        'lower',
        'title',
        'ucfirst',
        'lcfirst',
        'trim',
        'replace',
        'regex_replace',
        'truncate',
        'limit',
        'strip_tags',
        'sanitize',
        'entities',
        'slugify',
        'snake',
        'kebab',
        'studly',
        'word_count',
        'nl2br',
        'count',
        'length',
        'first',
        'last',
        'keys',
        'values',
        'join',
        'explode',
        'pluck',
        'sort',
        'reverse',
        'unique',
        'flatten',
        'chunk',
        'where',
        'pad',
        'add',
        'subtract',
        'multiply',
        'divide',
        'mod',
        'round',
        'floor',
        'ceil',
        'is_array',
        'is_empty',
        'is_numeric',
        'markdown',
    ];

    foreach ($requiredModifiers as $modifier) {
        expect($registry->has($modifier))->toBeTrue("Modifier [$modifier] should be registered.");
    }
});

it('returns fallback values for non-iterable collection modifiers', function (): void {
    $registry = coreModifierRegistry();
    $value    = new stdClass();

    expect($registry->apply('count', $value, [], []))->toBe(0)
        ->and($registry->apply('sort', $value, [], []))->toBe($value)
        ->and($registry->apply('first', $value, [], []))->toBe($value)
        ->and($registry->apply('last', $value, [], []))->toBe($value)
        ->and($registry->apply('pluck', $value, ['name'], []))->toBe($value)
        ->and($registry->apply('unique', $value, [], []))->toBe($value)
        ->and($registry->apply('where', $value, ['active', true], []))->toBe($value)
        ->and($registry->apply('chunk', $value, [2], []))->toBe($value)
        ->and($registry->apply('pluck', ['Alice', 'Bob'], [], []))->toBe(['Alice', 'Bob'])
        ->and($registry->apply('where', [['active' => true]], ['active'], []))->toBe([['active' => true]]);
});

it('keeps short strings unchanged and handles empty casing inputs', function (): void {
    $registry = coreModifierRegistry();

    expect($registry->apply('truncate', 'Hello', [5], []))->toBe('Hello')
        ->and($registry->apply('ucfirst', '', [], []))->toBe('')
        ->and($registry->apply('lcfirst', '', [], []))->toBe('');
});

it('converts scalars and stringable objects consistently', function (): void {
    $registry = coreModifierRegistry();

    $stringable = new class {
        public function __toString(): string
        {
            return 'value-from-object';
        }
    };

    expect($registry->apply('wrap', null, ['span'], []))->toBe('<span></span>')
        ->and($registry->apply('wrap', $stringable, ['span'], []))->toBe('<span>value-from-object</span>')
        ->and($registry->apply('join', $stringable, [], []))->toBe('value-from-object')
        ->and($registry->apply('join', new stdClass(), [], []))->toBe('');
});

it('covers numeric conversions used by truncate, add and format', function (): void {
    $registry = coreModifierRegistry();

    expect($registry->apply('truncate', 'Hello', ['5'], []))->toBe('Hello')
        ->and($registry->apply('truncate', 'Hello', [true], []))->toBe('H...')
        ->and($registry->apply('truncate', 'Hello', [new stdClass()], []))->toBe('...')
        ->and($registry->apply('add', '1.5', ['2.25'], []))->toBe(3.75)
        ->and($registry->apply('add', true, [false], []))->toBe(1.0)
        ->and($registry->apply('add', new stdClass(), [new stdClass()], []))->toBe(0.0)
        ->and($registry->apply('format', '1711929600', ['Y-m-d'], []))->toBe('2024-04-01');
});

it('returns null for empty first and the last item for single-item access', function (): void {
    $registry = coreModifierRegistry();

    expect($registry->apply('first', [], [], []))->toBeNull()
        ->and($registry->apply('last', [1, 2, 3], [], []))->toBe(3);
});

it('supports numeric parameter keys and object-aware pluck lookups', function (): void {
    $registry = coreModifierRegistry();

    $propertyObject = new class {
        public string $name = 'Alice';
    };

    $methodObject = new class {
        public function name(): string
        {
            return 'Bob';
        }
    };

    $getterObject = new class {
        public function __get(string $name): string
        {
            return $name === 'name' ? 'Carol' : '';
        }
    };

    $missingObject = new class {};

    expect($registry->apply('pluck', [[10, 'Alice'], [20, 'Bob']], [0], []))->toBe([10, 20])
        ->and($registry->apply('pluck', [$propertyObject], ['name'], []))->toBe(['Alice'])
        ->and($registry->apply('pluck', [$methodObject], ['name'], []))->toBe(['Bob'])
        ->and($registry->apply('pluck', [$getterObject], ['name'], []))->toBe(['Carol'])
        ->and($registry->apply('pluck', [$missingObject], ['name'], []))->toBe([null]);
});

it('keeps unique object instances by identity', function (): void {
    $registry = coreModifierRegistry();
    $first    = new stdClass();
    $second   = new stdClass();

    expect($registry->apply('unique', [$first, $first, $second], [], []))
        ->toBe([$first, $second]);
});

it('applies registered modifier interface implementations', function (): void {
    $registry = new ModifierRegistry();
    $registry->register('suffix', new class implements ModifierInterface {
        public function modify(mixed $value, array $params, array $context): mixed
        {
            return $value . ($params[0] ?? '') . ($context['tail'] ?? '');
        }
    });

    expect($registry->apply('suffix', 'Hello', ['!'], ['tail' => '?']))
        ->toBe('Hello!?');
});
