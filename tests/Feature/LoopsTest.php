<?php

declare(strict_types=1);

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

it('supports colon notation for variables', function (): void {
    $tpl  = '{{ user:profile:name }}';
    $data = ['user' => ['profile' => ['name' => 'Alice']]];
    expect(engine()->render($tpl, $data))->toBe('Alice');
});

it('provides next and previous values in paired array loops', function (): void {
    $tpl = '{{ songs }}{{ value }}(N:{{ next:value }}|P:{{ prev:value }}){{ /songs }}';
    $data = ['songs' => ['Brand New Funk', 'Parents Just Don\'t Understand', 'Summertime']];

    expect(engine()->render($tpl, $data))
        ->toBe('Brand New Funk(N:Parents Just Don\'t Understand|P:)Parents Just Don\'t Understand(N:Summertime|P:Brand New Funk)Summertime(N:|P:Parents Just Don\'t Understand)');
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
