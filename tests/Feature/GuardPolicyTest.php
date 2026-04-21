<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\GuardPolicy;

it('does not guard an empty variable path', function (): void {
    expect((new GuardPolicy(
        variables: ['user.password'],
    ))->guardsVariable(''))->toBeFalse();
});

it('renders guarded variables as empty strings in lenient mode', function (): void {
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        variables: ['user.password'],
    ));

    expect($engine->render('{{ user.password }}', [
        'user' => ['password' => 'secret'],
    ]))->toBe('');
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
    $engine = engine()->setGuardPolicy(new GuardPolicy(
        tags: ['dump'],
    ));

    expect($engine->render('{{ dump value=name }}', ['name' => 'Alice']))->toBe('');
});

it('throws for guarded tags in strict mode', function (): void {
    $engine = engine()
        ->setStrictMode(true)
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
