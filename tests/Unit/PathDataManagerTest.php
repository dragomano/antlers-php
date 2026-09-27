<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Runtime\PathDataManager;
use Bugo\Antlers\Runtime\RuntimeOptions;

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

    it('resolves magic getters without an opt-in', function (): void {
        $getterObject = new class {
            public function __get(string $name): ?string
            {
                return $name === 'name' ? 'Frank' : null;
            }
        };

        expect($this->pathDataManager->get('person.name', ['person' => $getterObject]))->toBe('Frank')
            ->and($this->pathDataManager->has('person.name', ['person' => $getterObject]))->toBeTrue();
    });

    it('does not call object methods while the opt-in is off', function (): void {
        $methodObject = new class {
            public function name(): string
            {
                return 'Eve';
            }
        };

        expect($this->pathDataManager->get('person.name', ['person' => $methodObject]))->toBeNull()
            ->and($this->pathDataManager->has('person.name', ['person' => $methodObject]))->toBeFalse();
    });

    it('calls object methods once the opt-in is enabled', function (): void {
        $options = new RuntimeOptions();
        $options->allowObjectMethodCalls = true;

        $paths = new PathDataManager($options);

        $methodObject = new class {
            public function name(): string
            {
                return 'Eve';
            }
        };

        expect($paths->get('person.name', ['person' => $methodObject]))->toBe('Eve')
            ->and($paths->has('person.name', ['person' => $methodObject]))->toBeTrue();
    });

    it('keeps private and protected properties invisible instead of raising a raw Error', function (): void {
        $options = new RuntimeOptions();
        $options->strict = true;

        $paths = new PathDataManager($options);

        $sealed = new class {
            private string $token = 'secret';

            protected string $id = 'shh';

            public function revealToken(): string
            {
                return $this->token;
            }
        };

        // An inaccessible member is a miss, not a failure: strict mode reports
        // undefined *variables*, so a guarded object read stays null in both
        // modes. What must never happen is a raw PHP Error escaping render.
        expect($this->pathDataManager->get('obj.token', ['obj' => $sealed]))->toBeNull()
            ->and($this->pathDataManager->get('obj.id', ['obj' => $sealed]))->toBeNull()
            ->and($this->pathDataManager->has('obj.token', ['obj' => $sealed]))->toBeFalse()
            ->and($this->pathDataManager->has('obj.id', ['obj' => $sealed]))->toBeFalse()
            ->and($paths->get('obj.token', ['obj' => $sealed]))->toBeNull()
            ->and($paths->has('obj.token', ['obj' => $sealed]))->toBeFalse()
            // has() agrees with get(): the hidden members do not exist to a template.
            ->and($paths->has('obj.revealToken', ['obj' => $sealed]))->toBeFalse();
    });

    it('never calls a private or protected method, even with the opt-in on', function (): void {
        $options = new RuntimeOptions();
        $options->allowObjectMethodCalls = true;

        $paths = new PathDataManager($options);

        $sealed = new class {
            private function token(): string
            {
                return 'secret';
            }

            protected function id(): string
            {
                return 'shh';
            }
        };

        // Symmetric with private properties: the method is not a reachable
        // member, so it is a miss (null / false), not a caught Error.
        expect($paths->get('obj.token', ['obj' => $sealed]))->toBeNull()
            ->and($paths->get('obj.id', ['obj' => $sealed]))->toBeNull()
            ->and($paths->has('obj.token', ['obj' => $sealed]))->toBeFalse()
            ->and($paths->has('obj.id', ['obj' => $sealed]))->toBeFalse();
    });

    it('asks __isset before trusting __get for existence', function (): void {
        $selective = new class {
            public function __isset(string $name): bool
            {
                return $name === 'known';
            }

            public function __get(string $name): ?string
            {
                return $name === 'known' ? 'value' : null;
            }
        };

        // Without __isset every key on a __get object counts as present; with
        // it, has() stops over-reporting the keys __get does not actually serve.
        expect($this->pathDataManager->has('obj.known', ['obj' => $selective]))->toBeTrue()
            ->and($this->pathDataManager->has('obj.unknown', ['obj' => $selective]))->toBeFalse()
            ->and($this->pathDataManager->get('obj.known', ['obj' => $selective]))->toBe('value');
    });

    it('wraps a Throwable raised by a magic __isset into the runtime policy', function (): void {
        $object = new class {
            public function __isset(string $name): bool
            {
                throw new LogicException('isset failure');
            }
        };

        expect($this->pathDataManager->has('obj.any', ['obj' => $object]))->toBeFalse();

        $options = new RuntimeOptions();
        $options->strict = true;

        $strictPaths = new PathDataManager($options);

        expect(fn(): bool => $strictPaths->has('obj.any', ['obj' => $object]))
            ->toThrow(AntlersRuntimeException::class, 'isset failure');
    });

    it('wraps a Throwable raised by an object member into the runtime policy', function (): void {
        $object = new class {
            public function boom(): string
            {
                throw new LogicException('domain failure');
            }
        };

        // The method only runs with the opt-in on, which is the case where a
        // foreign exception could escape render().
        $options = new RuntimeOptions();
        $options->allowObjectMethodCalls = true;

        $lenientPaths = new PathDataManager($options);

        expect($lenientPaths->get('obj.boom', ['obj' => $object]))->toBeNull();

        $options->strict = true;
        $strictPaths     = new PathDataManager($options);

        expect(fn(): mixed => $strictPaths->get('obj.boom', ['obj' => $object]))
            ->toThrow(AntlersRuntimeException::class, 'domain failure');
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

    it('takes a quoted subscript as the key itself, not as a variable name', function (string $path): void {
        expect($this->pathDataManager->get($path, [
            'items' => ['name' => 'literal', 'other' => 'dynamic'],
            'name'  => 'other',
        ]))->toBe('literal');
    })->with([
        'single quotes' => ["items['name']"],
        'double quotes' => ['items["name"]'],
    ]);

    it('resolves a subscript that is itself a path', function (): void {
        expect($this->pathDataManager->get('items[a.b]', [
            'items' => ['name' => 'found'],
            'a'     => ['b' => 'name'],
        ]))->toBe('found');
    });

    it('walks chained subscripts', function (): void {
        $data = ['matrix' => [['a', 'b'], ['c', 'd']]];

        expect($this->pathDataManager->get('matrix[1][0]', $data))->toBe('c')
            ->and($this->pathDataManager->has('matrix[1][0]', $data))->toBeTrue()
            ->and($this->pathDataManager->has('matrix[1][9]', $data))->toBeFalse();
    });

    it('reads an empty subscript as an empty key', function (): void {
        expect($this->pathDataManager->get('items[]', ['items' => ['' => 'blank']]))->toBe('blank');
    });
});
