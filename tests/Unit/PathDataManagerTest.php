<?php

declare(strict_types=1);

use Bugo\Antlers\Runtime\PathDataManager;

describe('PathDataManager', function (): void {
    beforeEach(function (): void {
        $this->pathDataManager = new PathDataManager();
    });

    it('returns null or false for empty paths', function (): void {
        expect($this->pathDataManager->get('', ['name' => 'Alice']))->toBeNull()
            ->and($this->pathDataManager->has('', ['name' => 'Alice']))->toBeFalse();
    });

    it('resolves top-level key', function (): void {
        expect($this->pathDataManager->get('name', ['name' => 'Alice']))->toBe('Alice');
    });

    it('resolves dot-notation path', function (): void {
        $data = ['user' => ['profile' => ['name' => 'Bob']]];
        expect($this->pathDataManager->get('user.profile.name', $data))->toBe('Bob');
    });

    it('returns null for missing key', function (): void {
        expect($this->pathDataManager->get('missing', []))->toBeNull();
    });

    it('returns null for missing nested key', function (): void {
        expect($this->pathDataManager->get('user.name', ['user' => []]))->toBeNull();
    });

    it('returns null when traversal continues after a missing nested key', function (): void {
        expect($this->pathDataManager->get('user.name.first', ['user' => []]))->toBeNull();
    });

    it('accesses object property', function (): void {
        $obj       = new stdClass();
        $obj->name = 'Charlie';
        expect($this->pathDataManager->get('person.name', ['person' => $obj]))->toBe('Charlie');
    });

    it('accesses object methods and magic getters', function (): void {
        $methodObject = new class {
            public function name(): string
            {
                return 'Eve';
            }
        };

        $getterObject = new class {
            public function __get(string $name): ?string
            {
                return $name === 'name' ? 'Frank' : null;
            }
        };

        expect($this->pathDataManager->get('person.name', ['person' => $methodObject]))->toBe('Eve')
            ->and($this->pathDataManager->get('person.name', ['person' => $getterObject]))->toBe('Frank');
    });

    it('accesses ArrayAccess offsets like array keys', function (): void {
        $person = new ArrayObject(['name' => 'Dana']);

        expect($this->pathDataManager->get('person.name', ['person' => $person]))->toBe('Dana')
            ->and($this->pathDataManager->has('person.name', ['person' => $person]))->toBeTrue();
    });

    it('accesses numeric array index', function (): void {
        $data = ['items' => ['a', 'b', 'c']];
        expect($this->pathDataManager->get('items[1]', $data))->toBe('b');
    });

    it('returns null for missing array-access parents and scalar containers', function (): void {
        expect($this->pathDataManager->get('items[1]', []))->toBeNull()
            ->and($this->pathDataManager->get('name.first', ['name' => 'Alice']))->toBeNull()
            ->and($this->pathDataManager->has('name.first', ['name' => 'Alice']))->toBeFalse();
    });

    it('returns false when array subscript parents or indexes are missing', function (): void {
        expect($this->pathDataManager->has('items[key]', []))->toBeFalse()
            ->and($this->pathDataManager->has('items[2]', ['items' => ['a', 'b']]))->toBeFalse();
    });

    it('resolves dynamic subscript keys from scope values and fallbacks', function (): void {
        expect($this->pathDataManager->get('items[key]', [
            'items' => ['1' => 'one'],
            'key'   => true,
        ]))->toBe('one')
            ->and($this->pathDataManager->get('items[key]', [
                'items' => ['' => 'empty'],
                'key'   => null,
            ]))->toBe('empty')
            ->and($this->pathDataManager->get('items[key]', [
                'items' => ['' => 'blank'],
                'key'   => new stdClass(),
            ]))->toBe('blank')
            ->and($this->pathDataManager->get('items[key]', [
                'items' => ['key' => 'literal'],
            ]))->toBe('literal');
    });
});
