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

it('evaluates the gatekeeper right-hand side only when the left-hand side is truthy', function (): void {
    expect(engine()->render('{{ show_bio ?= author.bio }}', [
        'show_bio' => true,
        'author'   => ['bio' => 'Writer'],
    ]))->toBe('Writer')
        ->and(engine()->render('{{ show_bio ?= author.bio }}', [
            'show_bio' => false,
            'author'   => ['bio' => 'Writer'],
        ]))->toBe('');
});

it('supports modifier chains on the gatekeeper right-hand side', function (): void {
    expect(engine()->render('{{ show_bio ?= author.bio | upper }}', [
        'show_bio' => true,
        'author'   => ['bio' => 'Writer'],
    ]))->toBe('WRITER')
        ->and(engine()->render('{{ show_bio ?= author.bio | upper }}', [
            'show_bio' => false,
            'author'   => ['bio' => 'Writer'],
        ]))->toBe('');
});
