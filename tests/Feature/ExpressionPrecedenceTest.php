<?php

declare(strict_types=1);

it('applies modifier chains after null coalescing for the whole expression', function (): void {
    expect(engine()->render('{{ name ?? "guest" | upper }}', ['name' => 'Bob']))->toBe('BOB')
        ->and(engine()->render('{{ missing ?? "guest" | upper }}'))->toBe('GUEST');
});

it('applies modifier chains in the ternary false branch without leaking to the whole ternary', function (): void {
    expect(engine()->render('{{ logged_in ? name : alt | upper }}', [
        'logged_in' => true,
        'name'      => 'Bob',
        'alt'       => 'Guest',
    ]))->toBe('Bob')
        ->and(engine()->render('{{ logged_in ? name : alt | upper }}', [
            'logged_in' => false,
            'name'      => 'Bob',
            'alt'       => 'Guest',
        ]))->toBe('GUEST');
});

it('supports modifier chains inside ternary branches', function (): void {
    expect(engine()->render('{{ logged_in ? name | upper : alt | lower }}', [
        'logged_in' => true,
        'name'      => 'Bob',
        'alt'       => 'Guest',
    ]))->toBe('BOB')
        ->and(engine()->render('{{ logged_in ? name | upper : alt | lower }}', [
            'logged_in' => false,
            'name'      => 'Bob',
            'alt'       => 'Guest',
        ]))->toBe('guest');
});

it('applies modifier chains to the whole ternary when the ternary is parenthesized', function (): void {
    expect(engine()->render('{{ (logged_in ? name : alt) | upper }}', [
        'logged_in' => true,
        'name'      => 'Bob',
        'alt'       => 'Guest',
    ]))->toBe('BOB')
        ->and(engine()->render('{{ (logged_in ? name : alt) | upper }}', [
            'logged_in' => false,
            'name'      => 'Bob',
            'alt'       => 'Guest',
        ]))->toBe('GUEST');
});
