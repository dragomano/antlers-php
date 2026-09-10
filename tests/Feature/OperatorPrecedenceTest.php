<?php

declare(strict_types=1);

it('applies modifiers before ternary operator', function (): void {
    expect(engine()->render('{{ name | upper ? "yes" : "no" }}', ['name' => 'john']))
        ->toBe('yes');

    expect(engine()->render('{{ name | upper ? "yes" : "no" }}', ['name' => '']))
        ->toBe('no');
});

it('evaluates power operator right-associatively', function (): void {
    expect(engine()->render('{{ 2 ** 3 ** 2 }}'))->toBe('512');
    expect(engine()->render('{{ 2 ** (3 ** 2) }}'))->toBe('512');
    expect(engine()->render('{{ (2 ** 3) ** 2 }}'))->toBe('64');
});

it('chains collection operators with modifiers', function (): void {
    expect(engine()->render('{{ items orderby(name) | first }}', [
        'items' => [
            ['name' => 'zebra'],
            ['name' => 'apple'],
            ['name' => 'banana'],
        ],
    ]))->toContain('apple');
});

it('respects standard operator precedence', function (): void {
    expect(engine()->render('{{ 2 + 3 * 4 }}'))->toBe('14');
    expect(engine()->render('{{ (2 + 3) * 4 }}'))->toBe('20');
    expect(engine()->render('{{ 10 - 2 ** 3 }}'))->toBe('2');
});

it('allows modifiers in ternary branches', function (): void {
    expect(engine()->render('{{ flag ? name | upper : name | lower }}', [
        'flag' => true,
        'name' => 'John',
    ]))->toBe('JOHN');

    expect(engine()->render('{{ flag ? name | upper : name | lower }}', [
        'flag' => false,
        'name' => 'John',
    ]))->toBe('john');
});

it('supports nested ternary expressions', function (): void {
    expect(engine()->render('{{ a ? "x" : b ? "y" : "z" }}', ['a' => true, 'b' => false]))
        ->toBe('x');

    expect(engine()->render('{{ a ? "x" : b ? "y" : "z" }}', ['a' => false, 'b' => true]))
        ->toBe('y');

    expect(engine()->render('{{ a ? "x" : b ? "y" : "z" }}', ['a' => false, 'b' => false]))
        ->toBe('z');
});
