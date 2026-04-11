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
    expect(engine()->render('{{ foreach items as item }}{{ item }}{{ /foreach }}', ['items' => []]))->toBe('');
});

it('handles paired variable tag iterating array', function (): void {
    $tpl  = '{{ items }}{{ value }}|{{ /items }}';
    $data = ['items' => [['value' => 'a'], ['value' => 'b']]];
    expect(engine()->render($tpl, $data))->toBe('a|b|');
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
