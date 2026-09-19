<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\GuardPolicy;

it('does not guard an empty variable path', function (): void {
    expect((new GuardPolicy(
        variables: ['user.password'],
    ))->guardsVariable(''))->toBeFalse();
});

it('rejects mutation after construction', function (): void {
    $policy = new GuardPolicy(
        variables: ['user.password'],
    );

    expect(static function () use ($policy): void {
        $policy->variables = [];
    })->toThrow(Error::class);
});

it('renders guarded variables as empty strings in lenient mode', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['user.password'],
    ));

    expect($engine->render('{{ user.password }}', [
        'user' => ['password' => 'secret'],
    ]))->toBe('');
});

it('guards a leaf rule reached through subscript notation', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['user.password'],
    ));

    expect($engine->render("{{ user['password'] }}", [
        'user' => ['password' => 'secret'],
    ]))->toBe('');
});

it('redacts a guarded field from whole-container output', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['user.password'],
    ));

    expect($engine->render('{{ user }}', [
        'user' => ['name' => 'Alice', 'password' => 'secret'],
    ]))->toBe('Alice');
});

it('redacts a guarded field inside a paired block', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['user.password'],
    ));

    expect($engine->render('{{ user }}{{ name }}:{{ password }}{{ /user }}', [
        'user' => ['name' => 'Alice', 'password' => 'secret'],
    ]))->toBe('Alice:');
});

it('redacts a guarded field across a foreach collection', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['users.password'],
    ));

    expect($engine->render('{{ foreach users as u }}{{ u.name }}:{{ u.password }},{{ /foreach }}', [
        'users' => [
            ['name' => 'Alice', 'password' => 'a'],
            ['name' => 'Bob', 'password' => 'b'],
        ],
    ]))->toBe('Alice:,Bob:,');
});

it('redacts a guarded field before pluck can reach it', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['users.password'],
    ));

    // Without the guard pluck would yield 'a,b'; redaction leaves only the
    // empty join separators, so no password value survives.
    expect($engine->render("{{ users | pluck:'password' | join:',' }}", [
        'users' => [
            ['name' => 'Alice', 'password' => 'a'],
            ['name' => 'Bob', 'password' => 'b'],
        ],
    ]))->toBe(',');
});

it('leaves sibling fields untouched when redacting', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['user.password'],
    ));

    expect($engine->render('{{ user }}{{ name }}{{ /user }}', [
        'user' => ['name' => 'Alice', 'password' => 'secret'],
    ]))->toBe('Alice');
});

it('redacts a guarded field from an object exposed as a container', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['user.password'],
    ));

    $user = new class {
        public string $name = 'Alice';

        public string $password = 'secret';
    };

    expect($engine->render('{{ user }}{{ name }}:{{ password }}{{ /user }}', [
        'user' => $user,
    ]))->toBe('Alice:');
});

it('leaves a scalar untouched when its path has a guarded descendant', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['user.password'],
    ));

    // "user" resolves to a scalar, so there is no field to strip.
    expect($engine->render('{{ user }}', [
        'user' => 'plain',
    ]))->toBe('plain');
});

it('redacts a nested guarded field deep in a container', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['user.profile.ssn'],
    ));

    expect($engine->render('{{ user }}{{ name }}-{{ profile.city }}-{{ profile.ssn }}{{ /user }}', [
        'user' => [
            'name' => 'Alice',
            'profile' => ['city' => 'NYC', 'ssn' => '123'],
        ],
    ]))->toBe('Alice-NYC-');
});

it('leaves a container untouched when a nested guarded path is absent', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['user.profile.ssn'],
    ));

    expect($engine->render('{{ user }}{{ name }}{{ /user }}', [
        'user' => ['name' => 'Bob'],
    ]))->toBe('Bob');
});

it('throws for guarded variables in strict mode', function (): void {
    $engine = engine()
        ->setStrictMode(true)
        ->setGuardPolicy(new GuardPolicy(
            variables: ['config'],
        ));

    expect(fn(): string => $engine->render('{{ config.db.host }}', [
        'config' => ['db' => ['host' => 'localhost']],
    ]))->toThrow(AntlersRuntimeException::class, 'Guarded variable: "config.db.host"');
});

it('treats guarded variables as null on the left side of ?? in strict mode', function (): void {
    $engine = engine()
        ->setStrictMode(true)
        ->setGuardPolicy(new GuardPolicy(
            variables: ['user.password'],
        ));

    expect($engine->render('{{ user.password ?? "hidden" }}', [
        'user' => ['password' => 'secret'],
    ]))->toBe('hidden');
});

it('returns an empty string for guarded tags in lenient mode', function (): void {
    $engine = engine()
        ->setDebug(true)
        ->setGuardPolicy(new GuardPolicy(
            tags: ['dump'],
        ));

    expect($engine->render('{{ dump value=name }}', ['name' => 'Alice']))->toBe('');
});

it('throws for guarded tags in strict mode', function (): void {
    $engine = engine()
        ->setStrictMode(true)
        ->setDebug(true)
        ->setGuardPolicy(new GuardPolicy(
            tags: ['dump'],
        ));

    expect(fn(): string => $engine->render('{{ dump value=name }}', ['name' => 'Alice']))
        ->toThrow(AntlersRuntimeException::class, 'Guarded tag: "dump"');
});

it('returns the original value for guarded modifiers in lenient mode', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        modifiers: ['upper'],
    ));

    expect($engine->render('{{ name | upper }}', ['name' => 'Alice']))->toBe('Alice');
});

it('throws for guarded modifiers in strict mode', function (): void {
    $engine = engine()
        ->setStrictMode(true)
        ->setGuardPolicy(new GuardPolicy(
            modifiers: ['upper'],
        ));

    expect(fn(): string => $engine->render('{{ name | upper }}', ['name' => 'Alice']))
        ->toThrow(AntlersRuntimeException::class, 'Guarded modifier: "upper"');
});
