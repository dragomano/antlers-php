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

/*
 * An interpolated string is parsed with a nested token stream. That stream must
 * not be left in place, or the rest of the surrounding expression is read from
 * it and silently discarded at its end.
 */

it('keeps parsing the expression around an interpolated string', function (): void {
    $data = ['name' => 'bob', 'x' => 1, 'n' => 'x'];

    expect(engine()->render('{{ "Hi {name}!" | upper }}', $data))->toBe('HI BOB!')
        ->and(engine()->render('{{ "a{x}" . "-tail" }}', $data))->toBe('a1-tail')
        ->and(engine()->render('{{ "a{x}" == "a1" }}', $data))->toBe('true')
        ->and(engine()->render('{{ "{x}" ? "Y" : "N" }}', $data))->toBe('Y')
        ->and(engine()->render('{{ ("hi {n}") . "!" }}', $data))->toBe('hi x!')
        ->and(engine()->render('{{ "{name}" | upper | trim }}', $data))->toBe('BOB');
});

it('keeps parsing around an interpolated string inside blocks', function (): void {
    expect(engine()->render('{{ if "{x}" == "1" }}Y{{ else }}N{{ /if }}', ['x' => 1]))->toBe('Y')
        ->and(engine()->render('{{ items }}{{ "v={value}" | upper }};{{ /items }}', ['items' => ['a', 'b']]))
        ->toBe('V=A;V=B;');
});

it('keeps collection operators working alongside nested streams', function (): void {
    $items = [['t' => 'a', 'n' => 1], ['t' => 'b', 'n' => 2], ['t' => 'a', 'n' => 3]];

    expect(engine()->render('{{ res = items groupby (t) }}{{ res }}{{ key }}:{{ values }}{{ n }}{{ /values }};{{ /res }}', [
        'items' => $items,
    ]))->toBe('a:13;b:2;')
        ->and(engine()->render('{{ items take (2) | pluck:"n" | join:"," }}', ['items' => $items]))->toBe('1,2')
        ->and(engine()->render('{{ res = items where (n > 1) }}{{ res }}{{ n }},{{ /res }}', ['items' => $items]))
        ->toBe('2,3,');
});
