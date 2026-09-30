<?php

declare(strict_types=1);

use Bugo\Antlers\Engine;
use Bugo\Antlers\Exceptions\AntlersRuntimeException;
use Bugo\Antlers\Exceptions\AntlersSyntaxException;

function strictSwitchEngine(): Engine
{
    return engine()->setStrictMode(true);
}

it('returns the value of the first truthy condition', function (): void {
    $tpl = "{{ switch((size == 'sm') => '35vw', (size == 'lg') => '75vw', (size == 'xl') => '90vw', () => '100vw') }}";

    expect(engine()->render($tpl, ['size' => 'sm']))->toBe('35vw')
        ->and(engine()->render($tpl, ['size' => 'lg']))->toBe('75vw')
        ->and(engine()->render($tpl, ['size' => 'xl']))->toBe('90vw');
});

it('prefers the earliest match over a later one', function (): void {
    $tpl = "{{ switch((x > 0) => 'positive', (x > 10) => 'big') }}";

    expect(engine()->render($tpl, ['x' => 20]))->toBe('positive');
});

it('uses the empty-parentheses pair as the no-match fallback', function (): void {
    $tpl = "{{ switch((size == 'sm') => 'small', () => 'unknown') }}";

    expect(engine()->render($tpl, ['size' => 'lg']))->toBe('unknown')
        ->and(engine()->render($tpl, ['size' => 'sm']))->toBe('small');
});

it('renders nothing when no condition matches and no fallback exists', function (): void {
    expect(engine()->render("{{ switch((x == 1) => 'one') }}", ['x' => 2]))->toBe('')
        ->and(engine()->render("{{ switch((x == 1) => 'one') ?? 'none' }}", ['x' => 2]))->toBe('none')
        ->and(engine()->render("{{ switch((x == 1) => 'one') ??? 'zero' }}", ['x' => 2]))->toBe('zero');
});

it('evaluates conditions as full expressions', function (): void {
    $tpl = "{{ switch((total > 10) => 'many', (total == 1) => 'one', (flag) => 'flagged') }}";

    expect(engine()->render($tpl, ['total' => 25]))->toBe('many')
        ->and(engine()->render($tpl, ['total' => 1]))->toBe('one')
        ->and(engine()->render($tpl, ['total' => 0, 'flag' => true]))->toBe('flagged');
});

it('evaluates values as full expressions', function (): void {
    expect(engine()->render('{{ switch((x == 1) => amount * 2) }}', ['x' => 1, 'amount' => 21]))->toBe('42')
        ->and(engine()->render("{{ switch((x == 1) => 'a' . 'b') }}", ['x' => 1]))->toBe('ab')
        ->and(engine()->render('{{ switch((x == 1) => name | upper) }}', ['x' => 1, 'name' => 'ann']))->toBe('ANN')
        ->and(engine()->render("{{ switch((x == 1) => flag ? 'yes' : 'no') }}", ['x' => 1, 'flag' => false]))->toBe('no');
});

it('accepts modifier chains applied to the whole switch', function (): void {
    expect(engine()->render("{{ switch((x == 1) => 'one') | upper }}", ['x' => 1]))->toBe('ONE');
});

it('feeds assignments and conditions as a regular expression', function (): void {
    expect(engine()->render("{{ cls = switch((s == 'lg') => 'big', () => 'small') }}{{ cls }}", ['s' => 'lg']))->toBe('big')
        ->and(engine()->render("{{ if switch((s == 'lg') => 'big') == 'big' }}yes{{ /if }}", ['s' => 'lg']))->toBe('yes')
        ->and(engine()->render("{{ switch((s == 'lg') => 'big') == 'big' ? 'y' : 'n' }}", ['s' => 'lg']))->toBe('y');
});

it('resolves inside string interpolation', function (): void {
    expect(engine()->render('{{ "v-{switch((x == 1) => name)}" }}', ['x' => 1, 'name' => 'ann']))->toBe('v-ann');
});

it('leaves the cycling switch tag working alongside the expression', function (): void {
    $tpl = '{{ switch between="odd|even" }}{{ switch((x == 1) => \'one\') }}';

    expect(engine()->render($tpl, ['x' => 1]))->toBe('oddone')
        ->and(engine()->render('{{ switch between="a|b" }}{{ switch between="a|b" }}'))->toBe('ab');
});

it('reports a missing arrow between condition and value', function (): void {
    expect(fn(): string => engine()->render("{{ switch((x == 1) 'one') }}", ['x' => 1]))
        ->toThrow(AntlersSyntaxException::class, 'Expected "=>" but found "one"');
});

it('reports an unparenthesized condition', function (): void {
    expect(fn(): string => engine()->render("{{ switch(x == 1 => 'one') }}", ['x' => 1]))
        ->toThrow(AntlersSyntaxException::class, 'Expected "(" around a switch condition but found "x"');
});

it('reports a missing value after the arrow', function (): void {
    expect(fn(): string => engine()->render('{{ switch((x == 1) => ) }}', ['x' => 1]))
        ->toThrow(AntlersSyntaxException::class, 'Expected a value after "=>" but found ")"');
});

it('reports an unterminated switch expression', function (): void {
    expect(fn(): string => engine()->render("{{ switch((x == 1) => 'one' }}", ['x' => 1]))
        ->toThrow(AntlersSyntaxException::class, 'Expected ")" but found end of expression');
});

it('treats undefined condition variables leniently by default', function (): void {
    expect(engine()->render("{{ switch((missing == 1) => 'one', () => 'd') }}", ['x' => 1]))->toBe('d');
});

it('throws on undefined condition variables in strict mode', function (): void {
    expect(fn(): string => strictSwitchEngine()->render("{{ switch((missing == 1) => 'one') }}", ['x' => 1]))
        ->toThrow(AntlersRuntimeException::class, 'Undefined variable: "missing"');
});
