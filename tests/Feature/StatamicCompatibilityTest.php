<?php

declare(strict_types=1);

/*
 * Executable notes on Statamic compatibility. Each case pins a construct that
 * is documented for Statamic Antlers, so a template moved over from Statamic
 * behaves the same way here.
 *
 * Constructs antlers-php deliberately does not support are out of scope — the
 * point of this file is that nothing behaves *differently* while looking the
 * same.
 */

describe('coalescing operators', function (): void {
    /*
     * Statamic: "The null coalescing operator ($a ?? $b) considers each variable
     * in a statement optional, returning the first one that passes a truthy
     * check." So ?? falls through on every falsy value, not just null.
     */
    it('falls back from any falsy left side', function (mixed $value): void {
        expect(engine()->render('{{ v ?? "fallback" }}', ['v' => $value]))->toBe('fallback');
    })->with([
        'null'         => [null],
        'false'        => [false],
        'empty string' => [''],
        'zero string'  => ['0'],
        'zero int'     => [0],
        'zero float'   => [0.0],
        'empty array'  => [[]],
    ]);

    it('falls back from an undefined left side', function (): void {
        expect(engine()->render('{{ missing ?? "fallback" }}'))->toBe('fallback');
    });

    it('keeps a truthy left side', function (): void {
        expect(engine()->render('{{ v ?? "fallback" }}', ['v' => 'Bob']))->toBe('Bob')
            ->and(engine()->render('{{ v ?? "fallback" }}', ['v' => 1]))->toBe('1')
            ->and(engine()->render('{{ v ?? "fallback" }}', ['v' => true]))->toBe('true');
    });

    /*
     * Statamic: "Use ??? to fall through only on null. Keeps 0, false, ''
     * intact."
     */
    it('keeps falsy but non-null values through the triple form', function (): void {
        expect(engine()->render('[{{ v ??? "fallback" }}]', ['v' => 0]))->toBe('[0]')
            ->and(engine()->render('[{{ v ??? "fallback" }}]', ['v' => false]))->toBe('[false]')
            ->and(engine()->render('[{{ v ??? "fallback" }}]', ['v' => '']))->toBe('[]')
            ->and(engine()->render('[{{ v ??? "fallback" }}]', ['v' => []]))->toBe('[]');
    });

    it('falls back from null through the triple form', function (): void {
        expect(engine()->render('{{ v ??? "fallback" }}', ['v' => null]))->toBe('fallback')
            ->and(engine()->render('{{ missing ??? "fallback" }}'))->toBe('fallback');
    });

    it('chains right to left', function (): void {
        expect(engine()->render('{{ a ?? b ?? "last" }}', ['a' => '', 'b' => '']))->toBe('last')
            ->and(engine()->render('{{ a ?? b ?? "last" }}', ['a' => '', 'b' => 'B']))->toBe('B')
            ->and(engine()->render('{{ a ??? b ??? "last" }}', ['a' => null, 'b' => 0]))->toBe('0');
    });

    it('mixes with modifier chains', function (): void {
        expect(engine()->render('{{ missing ?? "guest" | upper }}'))->toBe('GUEST')
            ->and(engine()->render('{{ missing ??? "guest" | upper }}'))->toBe('GUEST');
    });

    it('never throws for an undefined left side in strict mode', function (): void {
        $engine = engine()->setStrictMode(true);

        expect($engine->render('{{ missing ?? "fallback" }}'))->toBe('fallback')
            ->and($engine->render('{{ missing ??? "fallback" }}'))->toBe('fallback');
    });
});
