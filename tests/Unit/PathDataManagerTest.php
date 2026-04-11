<?php

declare(strict_types=1);

use Bugo\Antlers\Runtime\PathDataManager;

function pdm(): PathDataManager
{
    return new PathDataManager();
}

it('resolves top-level key', function (): void {
    expect(pdm()->get('name', ['name' => 'Alice']))->toBe('Alice');
});

it('resolves dot-notation path', function (): void {
    $data = ['user' => ['profile' => ['name' => 'Bob']]];
    expect(pdm()->get('user.profile.name', $data))->toBe('Bob');
});

it('returns null for missing key', function (): void {
    expect(pdm()->get('missing', []))->toBeNull();
});

it('returns null for missing nested key', function (): void {
    expect(pdm()->get('user.name', ['user' => []]))->toBeNull();
});

it('accesses object property', function (): void {
    $obj       = new stdClass();
    $obj->name = 'Charlie';
    expect(pdm()->get('person.name', ['person' => $obj]))->toBe('Charlie');
});

it('accesses numeric array index', function (): void {
    $data = ['items' => ['a', 'b', 'c']];
    expect(pdm()->get('items[1]', $data))->toBe('b');
});
