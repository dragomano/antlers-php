<?php

declare(strict_types=1);

use Bugo\Antlers\Runtime\ValueCoercion;

it('materialises collections and reports non-collections as null', function (): void {
    expect(ValueCoercion::toArray(['a']))->toBe(['a'])
        ->and(ValueCoercion::toArray(new ArrayIterator(['k' => 'v'])))->toBe(['k' => 'v'])
        ->and(ValueCoercion::toArray('a'))->toBeNull();
});

it('counts only what is numeric', function (): void {
    expect(ValueCoercion::toInt(3))->toBe(3)
        ->and(ValueCoercion::toInt(3.9))->toBe(3)
        ->and(ValueCoercion::toInt('4'))->toBe(4)
        ->and(ValueCoercion::toInt('four'))->toBe(0)
        ->and(ValueCoercion::toInt(true))->toBe(1)
        ->and(ValueCoercion::toInt(null))->toBe(0);
});

it('builds a scope frame from arrays and objects, and nothing from a scalar', function (): void {
    $object        = new stdClass();
    $object->title = 'A';

    expect(ValueCoercion::toScopeFrame(['title' => 'A', 2 => 'dropped']))->toBe(['title' => 'A'])
        ->and(ValueCoercion::toScopeFrame($object))->toBe(['title' => 'A'])
        ->and(ValueCoercion::toScopeFrame(new ArrayObject(['title' => 'A'])))->toBe(['title' => 'A'])
        ->and(ValueCoercion::toScopeFrame('A'))->toBeNull();
});

it('drops integer keys from a frame because no template can name them', function (): void {
    expect(ValueCoercion::stringKeys([0 => 'a', 'name' => 'b']))->toBe(['name' => 'b']);
});
