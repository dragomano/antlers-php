<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\GuardPolicy;

/*
 * A NodeProcessor is reused for the whole lifetime of an Engine. Per-render
 * state must therefore never survive a render — including a failed one.
 */

it('does not leak the data frame of a failed render into the next render', function (): void {
    $engine = engine();

    expect(fn(): string => $engine->render('{{ 1 / 0 }}', ['secret' => 'tenant-a']))
        ->toThrow(AntlersRuntimeException::class, 'Division by zero');

    expect($engine->render('[{{ secret }}]'))->toBe('[]');
});

it('does not leak sections of a failed render into the next render', function (): void {
    $engine = engine();

    expect(fn(): string => $engine->render('{{ section:head }}private{{ /section:head }}{{ 1 / 0 }}'))
        ->toThrow(AntlersRuntimeException::class, 'Division by zero');

    expect($engine->render('[{{ yield:head }}]'))->toBe('[]');
});

it('does not leak pushed stacks of a failed render into the next render', function (): void {
    $engine = engine();

    expect(fn(): string => $engine->render('{{ push:js }}private{{ /push:js }}{{ 1 / 0 }}'))
        ->toThrow(AntlersRuntimeException::class, 'Division by zero');

    expect($engine->render('[{{ stack:js }}]'))->toBe('[]');
});

it('resets increment counters after a failed render', function (): void {
    $engine = engine();

    expect($engine->render('{{ increment:counter }}-{{ increment:counter }}'))->toBe('1-2');

    expect(fn(): string => $engine->render('{{ 1 / 0 }}'))
        ->toThrow(AntlersRuntimeException::class, 'Division by zero');

    expect($engine->render('{{ increment:counter }}-{{ increment:counter }}'))->toBe('1-2');
});

it('resets once keys after a failed render', function (): void {
    $engine = engine();

    expect($engine->render('{{ once:banner }}shown{{ /once:banner }}'))->toBe('shown');

    expect(fn(): string => $engine->render('{{ 1 / 0 }}'))
        ->toThrow(AntlersRuntimeException::class, 'Division by zero');

    expect($engine->render('{{ once:banner }}shown{{ /once:banner }}'))->toBe('shown');
});

it('keeps strict mode enabled after an exception on the left side of ??', function (): void {
    $engine = engine()->setStrictMode(true);

    expect(fn(): string => $engine->render('{{ (1 / 0) ?? "fallback" }}'))
        ->toThrow(AntlersRuntimeException::class, 'Division by zero');

    expect(fn(): string => $engine->render('{{ missing }}'))
        ->toThrow(AntlersRuntimeException::class, 'Undefined variable: "missing"');
});

it('keeps the guard policy reporting after an exception on the left side of ??', function (): void {
    $engine = engine()
        ->setStrictMode(true)
        ->setGuardPolicy(new GuardPolicy(
            variables: ['secret'],
        ));

    expect(fn(): string => $engine->render('{{ (1 / 0) ?? "fallback" }}'))
        ->toThrow(AntlersRuntimeException::class, 'Division by zero');

    expect(fn(): string => $engine->render('{{ secret }}', ['secret' => 'value']))
        ->toThrow(AntlersRuntimeException::class, 'Guarded variable: "secret"');
});

/*
 * Nested renders (partials, layouts, a tag rendering its children) are part of
 * the same render and share its state; only a top-level render starts fresh.
 */

it('shares render state with nested renders but not across renders', function (): void {
    $engine = engine();
    $engine->addTag(
        'nest',
        fn(array $parameters, array $data, $processor, string $method, array $children): string
            => $processor->renderFragment($children, $data),
    );

    expect($engine->render('{{ nest on="1" }}{{ section:head }}A{{ /section:head }}{{ /nest }}[{{ yield:head }}]'))
        ->toBe('[A]')
        ->and($engine->render('[{{ yield:head }}]'))->toBe('[]');
});

/*
 * The flattened scope is memoised, so every write has to invalidate it.
 */

it('sees a value written earlier in the same frame', function (): void {
    expect(engine()->render('{{ set a = 1 }}{{ a }}{{ set a = 2 }}{{ a }}'))->toBe('12')
        ->and(engine()->render('{{ items }}{{ set n = value }}{{ n }};{{ /items }}', ['items' => ['a', 'b']]))
        ->toBe('a;b;');
});
