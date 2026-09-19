<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersRuntimeException;

it('keeps int arithmetic exact', function (string $template, string $expected): void {
    expect(engine()->render($template))->toBe($expected);
})->with([
    'addition beyond float precision' => ['{{ 9007199254740993 + 1 }}', '9007199254740994'],
    'subtraction'                     => ['{{ 9007199254740993 - 1 }}', '9007199254740992'],
    'multiplication'                  => ['{{ 3000000 * 3000000 }}', '9000000000000'],
    'power'                           => ['{{ 2 ** 62 }}', '4611686018427387904'],
    'integer modulo'                  => ['{{ 7 % 3 }}', '1'],
]);

it('still computes float arithmetic', function (string $template, string $expected): void {
    expect(engine()->render($template))->toBe($expected);
})->with([
    'float addition'  => ['{{ 1.5 + 1 }}', '2.5'],
    'float modulo'    => ['{{ 7.5 % 2 }}', '1.5'],
    'float power'     => ['{{ 2 ** 0.5 }}', '1.4142135623731'],
    'division'        => ['{{ 10 / 4 }}', '2.5'],
]);

it('routes division and modulo by zero through the lenient policy', function (): void {
    expect(engine()->render('{{ 1 / 0 }}'))->toBe('')
        ->and(engine()->render('{{ 1 % 0 }}'))->toBe('');
});

it('reports division and modulo by zero in strict mode', function (): void {
    expect(fn(): string => engine()->setStrictMode(true)->render('{{ 1 / 0 }}'))
        ->toThrow(AntlersRuntimeException::class, 'Division by zero');

    expect(fn(): string => engine()->setStrictMode(true)->render('{{ 1 % 0 }}'))
        ->toThrow(AntlersRuntimeException::class, 'Modulo by zero');
});

it('adds ints through the add modifier like the + operator', function (): void {
    expect(engine()->render('{{ x | add:1 }}', ['x' => 9007199254740993]))
        ->toBe(engine()->render('{{ x + 1 }}', ['x' => 9007199254740993]))
        ->toBe('9007199254740994');
});

it('compares strings and numbers through the unified comparator', function (): void {
    expect(engine()->render('{{ "b" > "a" }}'))->toBe('true');
    expect(engine()->render('{{ "b" <=> "a" }}'))->toBe('1');
});

it('orders and compares mixed values through the same comparator', function (): void {
    $items = [['price' => 10], ['price' => 2], ['price' => 7]];

    expect(engine()->render('{{ items orderby(price) | first }}', ['items' => $items]))
        ->toBe(engine()->render('{{ items | sort:price | first }}', ['items' => $items]))
        ->toBe('2');
    expect(engine()->render('{{ a <=> b }}', ['a' => true, 'b' => 2]))->toBe('-1');
});
