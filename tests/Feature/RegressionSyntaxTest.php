<?php

declare(strict_types=1);

it('keeps nested paired tags stable when conditions and loops are combined', function (): void {
    $tpl = <<<'ANTLERS'
    {{ items }}
    {{ if active }}{{ details }}{{ value }}{{ /details }}{{ else }}inactive{{ /if }}|
    {{ /items }}
    ANTLERS;

    expect(engine()->render($tpl, [
        'items' => [
            ['active' => true, 'details' => ['A', 'B']],
            ['active' => false, 'details' => ['C']],
        ],
    ]))->toBe("\nAB|\n\ninactive|\n");
});

it('keeps nested paired variables stable when the same name is also a registered tag', function (): void {
    $e = engine();
    $e->addTag('entries', fn(): string => 'tag-value');

    expect($e->render('{{ $entries }}{{ title }}:{{ tags }}{{ value }},{{ /tags }}|{{ /$entries }}', [
        'entries' => [
            ['title' => 'One', 'tags' => ['a', 'b']],
            ['title' => 'Two', 'tags' => ['c']],
        ],
    ]))->toBe('One:a,b,|Two:c,|');
});

it('keeps ambiguous ternary and modifier syntax stable inside nested blocks', function (): void {
    $tpl = <<<'ANTLERS'
    {{ users }}
    {{ if active }}{{ name ?? "guest" | upper }}{{ else }}{{ alt | lower }}{{ /if }}|
    {{ /users }}
    ANTLERS;

    expect(engine()->render($tpl, [
        'users' => [
            ['active' => true, 'name' => 'Alice', 'alt' => 'IGNORED'],
            ['active' => false, 'name' => null, 'alt' => 'GUEST'],
        ],
    ]))->toBe("\nALICE|\n\nguest|\n");
});

/*
 * Pairing is resolved per occurrence, not per name. A name that is closed
 * somewhere in the template must not turn its other occurrences into blocks
 * that swallow everything after them.
 */

it('keeps an interpolation of a name that is paired later in the template', function (): void {
    $tpl = '{{ if posts }}<p>{{ posts | length }} posts</p>{{ /if }}'
        . '{{ posts }}<li>{{ title }}</li>{{ /posts }}';

    expect(engine()->render($tpl, ['posts' => [['title' => 'a'], ['title' => 'b']]]))
        ->toBe('<p>2 posts</p><li>a</li><li>b</li>');
});

it('keeps an interpolation before a paired block of the same name', function (): void {
    expect(engine()->render('Total: {{ items | length }} {{ items }}[{{ value }}]{{ /items }}', [
        'items' => ['x', 'y'],
    ]))->toBe('Total: 2 [x][y]');

    expect(engine()->render('{{ label }}|{{ label }}A{{ /label }}', ['label' => 'X']))->toBe('X|A');
});

it('keeps a path interpolation whose root is paired later', function (): void {
    expect(engine()->render('{{ user.name }} / {{ user }}{{ name }}{{ /user }}', [
        'user' => ['name' => 'Ann'],
    ]))->toBe('Ann / Ann');
});

it('nests blocks that share a name', function (): void {
    expect(engine()->render('{{ items }}{{ items }}[{{ v }}]{{ /items }}{{ /items }}', [
        'items' => [['items' => [['v' => 1], ['v' => 2]]]],
    ]))->toBe('[1][2]');
});

it('pairs a block and leaves a later unpaired occurrence as a variable', function (): void {
    expect(engine()->render('{{ items }}A{{ /items }}|{{ items }}', ['items' => 'X']))->toBe('A|X');
});
