<?php

declare(strict_types=1);

it('renders recursive children at arbitrary depth', function (): void {
    $items = [['title' => 'A', 'children' => [['title' => 'B', 'children' => [['title' => 'C']]]]]];

    expect(engine()->render(
        '{{ items }}{{ title }}{{ if children }}>{{ *recursive children* }}{{ /if }}{{ /items }}',
        ['items' => $items],
    ))->toBe('A>B>C');
});

it('limits recursive children depth', function (): void {
    $items = [['title' => 'A', 'children' => [[
        'title' => 'B',
        'children' => [['title' => 'C', 'children' => [['title' => 'D']]]],
    ]]]];

    expect(engine()->render(
        '{{ items }}{{ title }}{{ *recursive children max_depth="2"* }}{{ /items }}',
        ['items' => $items],
    ))->toBe('ABC');
});

it('supports max depth after the recursive marker', function (): void {
    $items = [['title' => 'A', 'children' => [['title' => 'B', 'children' => [['title' => 'C']]]]]];

    expect(engine()->render(
        '{{ items }}{{ title }}{{ *recursive children* max_depth="1" }}{{ /items }}',
        ['items' => $items],
    ))->toBe('AB');
});

it('renders a recursive marker outside a paired block as empty', function (): void {
    expect(engine()->render('{{ *recursive children* }}', ['children' => [['title' => 'A']]]))->toBe('');
});
