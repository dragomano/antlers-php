<?php

declare(strict_types=1);

/*
 * The scope model is fixed by spec.md §11: the root render, every loop
 * iteration, every partial and every section body own a frame; conditions and
 * truthy blocks do not. An assignment writes into the innermost frame, so where
 * frames are opened decides what survives a block.
 */

it('keeps an assignment made inside a condition body', function (string $template): void {
    expect(engine()->render($template . '[{{ f }}]'))->toBe('[yes]');
})->with([
    'set in if'         => ['{{ if true }}{{ set f = "yes" }}{{ /if }}'],
    'expression in if'  => ['{{ if true }}{{ f = "yes" }}{{ /if }}'],
    'unless'            => ['{{ unless false }}{{ set f = "yes" }}{{ /unless }}'],
    'elseif branch'     => ['{{ if false }}a{{ elseif true }}{{ set f = "yes" }}{{ /if }}'],
    'else branch'       => ['{{ if false }}a{{ else }}{{ set f = "yes" }}{{ /if }}'],
    'nested conditions' => ['{{ if true }}{{ if true }}{{ set f = "yes" }}{{ /if }}{{ /if }}'],
]);

it('keeps an assignment made inside a truthy scalar block', function (): void {
    expect(engine()->render('{{ flag }}{{ set f = "yes" }}{{ /flag }}[{{ f }}]', ['flag' => true]))
        ->toBe('[yes]');
});

it('keeps a loop assignment inside its own iteration', function (): void {
    expect(engine()->render('{{ foreach items as item }}{{ set doubled = item * 2 }}{{ doubled }},{{ /foreach }}', [
        'items' => [1, 2],
    ]))->toBe('2,4,');
});

it('does not leak a loop assignment out of the loop', function (string $template): void {
    expect(engine()->render($template . '[{{ seen }}]', ['items' => [1, 2]]))->toBe('[]');
})->with([
    'foreach'         => ['{{ foreach items as item }}{{ set seen = item }}{{ /foreach }}'],
    'paired variable' => ['{{ items }}{{ set seen = value }}{{ /items }}'],
    'for'             => ['{{ for 1 to 2 }}{{ set seen = value }}{{ /for }}'],
]);

it('writes a condition body inside a loop into the iteration, not the parent', function (): void {
    expect(engine()->render(
        '{{ foreach items as item }}{{ if true }}{{ set seen = item }}{{ /if }}{{ seen }},{{ /foreach }}[{{ seen }}]',
        ['items' => [1, 2]],
    ))->toBe('1,2,[]');
});
