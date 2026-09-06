<?php

declare(strict_types=1);

it('shows the same string with and without a modifier', function (mixed $value, string $expected): void {
    expect(engine()->render('{{ v }}', ['v' => $value]))->toBe($expected)
        ->and(engine()->render('{{ v | upper }}', ['v' => $value]))->toBe(strtoupper($expected));
})->with([
    'true'        => [true, 'true'],
    'false'       => [false, 'false'],
    'int'         => [7, '7'],
    'float'       => [1.5, '1.5'],
    'string'      => ['ab', 'ab'],
    'string list' => [['x', 'y'], 'xy'],
    'nested list' => [[['a'], 'b'], 'ab'],
    'null'        => [null, ''],
]);

it('keeps the display form through a chain that has to see the text', function (): void {
    expect(engine()->render('{{ flag | truncate:2 }}', ['flag' => true]))->toBe('tr...')
        ->and(engine()->render('{{ parts | length }}', ['parts' => ['ab', 'cd']]))->toBe('2');
});
