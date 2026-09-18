<?php

declare(strict_types=1);

use Bugo\Antlers\Engine;
use Bugo\Antlers\Exceptions\AntlersRuntimeException;

function loopMetadataBody(): string
{
    return '{{ count }}/{{ index }}/{{ total }}/{{ total_results }}/{{ no_results ? "T" : "N" }}'
        . '/{{ first ? "F" : "-" }}/{{ last ? "L" : "-" }}/{{ odd ? "o" : "e" }}/{{ key }};';
}

it('iterates array with foreach', function (): void {
    $tpl  = '{{ foreach items as item }}{{ item }}|{{ /foreach }}';
    $data = ['items' => ['a', 'b', 'c']];
    expect(engine()->render($tpl, $data))->toBe('a|b|c|');
});

it('provides loop variables in foreach', function (): void {
    $tpl  = '{{ foreach items as item }}{{ count }}{{ /foreach }}';
    $data = ['items' => ['x', 'y', 'z']];
    expect(engine()->render($tpl, $data))->toBe('123');
});

it('provides first and last in foreach', function (): void {
    $tpl  = '{{ foreach items as item }}{{ if first }}[{{ /if }}{{ item }}{{ if last }}]{{ /if }}{{ /foreach }}';
    $data = ['items' => ['a', 'b', 'c']];
    expect(engine()->render($tpl, $data))->toBe('[abc]');
});

it('provides key alias in foreach', function (): void {
    $tpl  = '{{ foreach data as k => v }}{{ k }}:{{ v }}|{{ /foreach }}';
    $data = ['data' => ['x' => 1, 'y' => 2]];
    expect(engine()->render($tpl, $data))->toBe('x:1|y:2|');
});

it('iterates array of objects with foreach', function (): void {
    $tpl  = '{{ foreach users as user }}{{ user.name }},{{ /foreach }}';
    $data = ['users' => [
        ['name' => 'Alice'],
        ['name' => 'Bob'],
    ]];
    expect(engine()->render($tpl, $data))->toBe('Alice,Bob,');
});

it('renders for loop from 1 to 3', function (): void {
    $tpl = '{{ for 1 to 3 }}{{ value }}{{ /for }}';
    expect(engine()->render($tpl))->toBe('123');
});

it('provides count in for loop', function (): void {
    $tpl = '{{ for 1 to 3 }}{{ count }}{{ /for }}';
    expect(engine()->render($tpl))->toBe('123');
});

it('handles for loop with zero items', function (): void {
    expect(engine()->render('{{ foreach items as item }}{{ item }}{{ /foreach }}', ['items' => []]))
        ->toBe('');
});

it('handles paired variable tag iterating array', function (): void {
    $tpl  = '{{ items }}{{ value }}|{{ /items }}';
    $data = ['items' => [['value' => 'a'], ['value' => 'b']]];
    expect(engine()->render($tpl, $data))->toBe('a|b|');
});

it('iterates Traversable collections like arrays in paired loops', function (): void {
    $tpl  = '{{ items }}{{ title }}|{{ /items }}';
    $data = ['items' => new ArrayIterator([
        ['title' => 'A'],
        ['title' => 'B'],
    ])];

    expect(engine()->render($tpl, $data))->toBe('A|B|');
});

it('materializes Traversable values once and shares truthiness with iteration', function (): void {
    $items = (function (): Generator {
        yield ['title' => 'A'];
        yield ['title' => 'B'];
    })();

    expect(engine()->render('{{ if items }}Y{{ /if }}{{ items }}{{ title }}|{{ /items }}', ['items' => $items]))
        ->toBe('YA|B|');
});

it('treats an empty Traversable as false and empty in paired blocks', function (): void {
    $items = new ArrayIterator([]);

    expect(engine()->render('{{ if items }}Y{{ /if }}{{ items }}X{{ /items }}', ['items' => $items]))
        ->toBe('');
});

it('supports colon notation for variables', function (): void {
    $tpl  = '{{ user:profile:name }}';
    $data = ['user' => ['profile' => ['name' => 'Alice']]];
    expect(engine()->render($tpl, $data))->toBe('Alice');
});

it('provides next and previous values in paired array loops', function (): void {
    $tpl = '{{ songs }}{{ value }}(N:{{ next:value }}|P:{{ prev:value }}){{ /songs }}';
    $data = ['songs' => ['Brand New Funk', "Parents Just Don't Understand", 'Summertime']];

    expect(engine()->render($tpl, $data))
        ->toBe("Brand New Funk(N:Parents Just Don't Understand|P:)Parents Just Don't Understand(N:Summertime|P:Brand New Funk)Summertime(N:|P:Parents Just Don't Understand)");
});

it('provides next and previous nested fields for array items in paired loops', function (): void {
    $tpl = '{{ songs }}{{ title }}(N:{{ next:title }}|P:{{ prev:title }}){{ /songs }}';
    $data = ['songs' => [
        ['title' => 'Brand New Funk', 'artist' => ['name' => 'DJ Jazzy Jeff & The Fresh Prince']],
        ['title' => 'Summertime', 'artist' => ['name' => 'DJ Jazzy Jeff & The Fresh Prince']],
        ['title' => 'Boom! Shake the Room', 'artist' => ['name' => 'DJ Jazzy Jeff & The Fresh Prince']],
    ]];

    expect(engine()->render($tpl, $data))
        ->toBe('Brand New Funk(N:Summertime|P:)Summertime(N:Boom! Shake the Room|P:Brand New Funk)Boom! Shake the Room(N:|P:Summertime)');
});

it('provides next and previous nested fields for object items in paired loops', function (): void {
    $songA = (object) ['title' => 'Brand New Funk', 'artist' => (object) ['name' => 'DJ Jazzy Jeff & The Fresh Prince']];
    $songB = (object) ['title' => 'Summertime', 'artist' => (object) ['name' => 'DJ Jazzy Jeff & The Fresh Prince']];
    $songC = (object) ['title' => 'Boom! Shake the Room', 'artist' => (object) ['name' => 'DJ Jazzy Jeff & The Fresh Prince']];

    $tpl = '{{ songs }}{{ artist:name }}(N:{{ next:artist:name }}|P:{{ prev:artist:name }}){{ /songs }}';

    expect(engine()->render($tpl, ['songs' => [$songA, $songB, $songC]]))
        ->toBe(
            'DJ Jazzy Jeff & The Fresh Prince(N:DJ Jazzy Jeff & The Fresh Prince|P:)'
            . 'DJ Jazzy Jeff & The Fresh Prince(N:DJ Jazzy Jeff & The Fresh Prince|P:DJ Jazzy Jeff & The Fresh Prince)'
            . 'DJ Jazzy Jeff & The Fresh Prince(N:|P:DJ Jazzy Jeff & The Fresh Prince)',
        );
});

it('treats ArrayAccess items like arrays for loop scope and next/prev lookups', function (): void {
    $tpl = '{{ songs }}{{ title }}(N:{{ next:title }}|P:{{ prev:title }}){{ /songs }}';
    $data = ['songs' => [
        new ArrayObject(['title' => 'Brand New Funk']),
        new ArrayObject(['title' => 'Summertime']),
        new ArrayObject(['title' => 'Boom! Shake the Room']),
    ]];

    expect(engine()->render($tpl, $data))
        ->toBe('Brand New Funk(N:Summertime|P:)Summertime(N:Boom! Shake the Room|P:Brand New Funk)Boom! Shake the Room(N:|P:Summertime)');
});

it('renders paired object values with the same local field access as associative arrays', function (): void {
    $song        = new stdClass();
    $song->title = 'Summertime';

    expect(engine()->render('{{ song }}{{ title }}{{ /song }}', ['song' => $song]))
        ->toBe('Summertime');
});

it('provides odd and even in foreach', function (): void {
    $tpl  = '{{ foreach items as item }}{{ odd ? "o" : "e" }}{{ /foreach }}';
    $data = ['items' => [1, 2, 3, 4]];
    expect(engine()->render($tpl, $data))->toBe('oeoe');
});

it('provides index (0-based) in foreach', function (): void {
    $tpl  = '{{ foreach items as item }}{{ index }}{{ /foreach }}';
    $data = ['items' => ['a', 'b', 'c']];
    expect(engine()->render($tpl, $data))->toBe('012');
});

it('lets loop scope shadow globals without leaking after the loop', function (): void {
    $e = engine();
    $e->addGlobal('value', 'Global');

    expect($e->render('{{ foreach items as item }}{{ value }}{{ /foreach }}|{{ value }}', [
        'items' => ['A', 'B'],
    ]))->toBe('AB|Global');
});

/*
 * A paired block iterates whenever the keys are all integers. Gaps are normal
 * in data that went through array_filter() or unset(), and 1-based arrays are
 * normal in hand-written data; only string keys mean "one item, use its fields".
 */

it('iterates int-keyed arrays whose keys are not a list', function (
    array $items,
    string $expected,
): void {
    expect(engine()->render('{{ items }}{{ key }}={{ value }},{{ /items }}', ['items' => $items]))
        ->toBe($expected);
})->with([
    'gap from a filter' => [[0 => 'a', 2 => 'c'], '0=a,2=c,'],
    'one-based'         => [[1 => 'x', 2 => 'y'], '1=x,2=y,'],
    'reversed'          => [[2 => 'x', 1 => 'y'], '2=x,1=y,'],
]);

it('keeps loop variables consistent for a gapped array', function (): void {
    expect(engine()->render('{{ items }}{{ count }}/{{ total }}{{ first ? "F" : "" }}{{ last ? "L" : "" }}|{{ /items }}', [
        'items' => array_filter([1, 0, 3]),
    ]))->toBe('1/2F|2/2L|');
});

it('renders a single frame when the keys are strings', function (
    array $item,
    string $expected,
): void {
    expect(engine()->render('{{ user }}{{ name }}{{ /user }}', ['user' => $item]))->toBe($expected);
})->with([
    'associative' => [['name' => 'Alice'], 'Alice'],
    'mixed keys'  => [[0 => 'ignored', 'name' => 'Alice'], 'Alice'],
]);

it('exposes the same loop metadata in foreach, for and paired blocks', function (string $template): void {
    expect(engine()->render($template, ['items' => ['x', 'y']]))
        ->toBe('1/0/2/2/N/F/-/o/0;2/1/2/2/N/-/L/e/1;');
})->with([
    'foreach' => '{{ foreach items as item }}' . loopMetadataBody() . '{{ /foreach }}',
    'for'     => '{{ for 1 to 2 }}' . loopMetadataBody() . '{{ /for }}',
    'paired'  => '{{ items }}' . loopMetadataBody() . '{{ /items }}',
]);

it('provides key, prev and next in for loops', function (string $template, string $expected): void {
    expect(engine()->render($template))->toBe($expected);
})->with([
    'ascending'  => [
        '{{ for 1 to 3 }}{{ key }}:{{ value }}({{ prev.value }}<{{ next.value }})|{{ /for }}',
        '0:1(<2)|1:2(1<3)|2:3(2<)|',
    ],
    'descending' => [
        '{{ for 3 to 1 }}{{ key }}:{{ value }}({{ prev.value }}<{{ next.value }})|{{ /for }}',
        '0:3(<2)|1:2(3<1)|2:1(2<)|',
    ],
]);

it('keeps total as an alias of total_results', function (string $template): void {
    expect(engine()->render($template, ['items' => ['a', 'b']]))->toBe('2=2;2=2;');
})->with([
    'foreach' => '{{ foreach items as item }}{{ total }}={{ total_results }};{{ /foreach }}',
    'for'     => '{{ for 1 to 2 }}{{ total }}={{ total_results }};{{ /for }}',
    'paired'  => '{{ items }}{{ total }}={{ total_results }};{{ /items }}',
]);

it('reports no_results as false for every loop iteration', function (string $template): void {
    expect(engine()->render($template, ['items' => ['a', 'b']]))->toBe('NN');
})->with([
    'foreach' => '{{ foreach items as item }}{{ no_results ? "Y" : "N" }}{{ /foreach }}',
    'for'     => '{{ for 1 to 2 }}{{ no_results ? "Y" : "N" }}{{ /for }}',
    'paired'  => '{{ items }}{{ no_results ? "Y" : "N" }}{{ /items }}',
]);

it('renders no iteration for an empty iterable, so no_results cannot be true', function (string $template): void {
    expect(engine()->render($template, ['items' => []]))->toBe('');
})->with([
    'foreach' => '{{ foreach items as item }}{{ if no_results }}Y{{ /if }}body{{ /foreach }}',
    'paired'  => '{{ items }}{{ if no_results }}Y{{ /if }}body{{ /items }}',
]);

function metadataItem(): array
{
    return [
        'count'         => 'field',
        'index'         => 'field',
        'total'         => 'field',
        'total_results' => 'field',
        'no_results'    => 'field',
        'first'         => 'field',
        'last'          => 'field',
        'odd'           => 'field',
        'even'          => 'field',
        'key'           => 'field',
        'prev'          => 'field',
        'next'          => 'field',
    ];
}

it('keeps loop metadata ahead of item fields that share their names', function (string $template): void {
    expect(engine()->render($template, ['items' => [metadataItem()]]))
        ->toBe('1/0/1/1/N/F/L/o/0;');
})->with([
    'foreach' => '{{ foreach items as item }}' . loopMetadataBody() . '{{ /foreach }}',
    'for'     => '{{ for 1 to 1 }}' . loopMetadataBody() . '{{ /for }}',
    'paired'  => '{{ items }}' . loopMetadataBody() . '{{ /items }}',
]);

it('keeps prev and next metadata ahead of item fields that share their names', function (string $template): void {
    expect(engine()->render($template, ['items' => [metadataItem()]]))->toBe('[]');
})->with([
    'foreach' => '{{ foreach items as item }}[{{ prev }}{{ next }}]{{ /foreach }}',
    'for'     => '{{ for 1 to 1 }}[{{ prev }}{{ next }}]{{ /for }}',
    'paired'  => '{{ items }}[{{ prev }}{{ next }}]{{ /items }}',
]);

it('reaches a shadowed item field through the alias', function (string $template): void {
    expect(engine()->render($template, ['items' => [['count' => 'C', 'key' => 'K']]]))
        ->toBe('C/K|1/0;');
})->with([
    'foreach alias'     => ['{{ foreach items as item }}{{ item.count }}/{{ item.key }}|{{ count }}/{{ key }};{{ /foreach }}'],
    'foreach tag alias' => ['{{ foreach:items as="k|v" }}{{ v.count }}/{{ v.key }}|{{ count }}/{{ key }};{{ /foreach:items }}'],
]);

it('defines value for an element that brings no fields of its own', function (string $template, array $items, string $expected): void {
    expect(engine()->render($template, ['items' => $items]))->toBe($expected);
})->with([
    'foreach with an empty array element'  => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [[]], '[]'],
    'foreach with an empty object element' => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [new stdClass()], '[]'],
    'paired with an empty array element'   => ['{{ items }}[{{ value }}]{{ /items }}', [[]], '[]'],
    'foreach with a pure list element'     => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [[1, 2]], '[12]'],
    'foreach with a scalar element'        => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', ['x'], '[x]'],
    'foreach with a null element'          => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [null], '[]'],
    'foreach with an item owning value'    => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [['value' => 'own']], '[own]'],
]);

it('defines value for an element that brings no fields of its own in strict mode', function (string $template, array $items, string $expected): void {
    expect((new Engine())->setStrictMode(true)->render($template, ['items' => $items]))->toBe($expected);
})->with([
    'foreach with an empty array element'  => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [[]], '[]'],
    'foreach with an empty object element' => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [new stdClass()], '[]'],
    'paired with an empty array element'   => ['{{ items }}[{{ value }}]{{ /items }}', [[]], '[]'],
    'foreach with a pure list element'     => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [[1, 2]], '[12]'],
    'foreach with an empty string'         => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [''], '[]'],
    'foreach with a zero'                  => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [0], '[0]'],
    'foreach with a false'                 => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [false], '[false]'],
    'foreach with a null element'          => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [null], '[]'],
    'foreach with an item owning value'    => ['{{ foreach items as item }}[{{ value }}]{{ /foreach }}', [['value' => 'own']], '[own]'],
]);

it('leaves value undefined for an element that brings fields of its own', function (string $template, array $items): void {
    $data = ['items' => $items];

    expect(engine()->render($template, $data))->toBe('[|]')
        ->and(fn(): string => (new Engine())->setStrictMode(true)->render($template, $data))
        ->toThrow(AntlersRuntimeException::class, 'Undefined variable: "value"');
})->with([
    'foreach'                       => ['{{ foreach items as item }}[{{ value }}|{{ value.title }}]{{ /foreach }}', [['title' => 'a']]],
    'paired'                        => ['{{ items }}[{{ value }}|{{ value.title }}]{{ /items }}', [['title' => 'a']]],
    'foreach with colliding fields' => ['{{ foreach items as item }}[{{ value }}|{{ value.title }}]{{ /foreach }}', [['count' => 'C']]],
    'paired with colliding fields'  => ['{{ items }}[{{ value }}|{{ value.title }}]{{ /items }}', [['count' => 'C']]],
]);
