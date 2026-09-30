<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersSyntaxException;

it('reads a bare word after a colon as a string literal', function (): void {
    $rows = [['name' => 'a'], ['name' => 'b']];

    expect(engine()->render('{{ rows | pluck:name | join:"," }}', ['rows' => $rows]))->toBe('a,b');
    expect(engine()->render('{{ rows | where:name:a | join:"," }}', ['rows' => $rows]))->toBe('a');
    expect(engine()->render('{{ text | surround:x }}', ['text' => 'mid']))->toBe('xmidx');
    expect(engine()->render('{{ text | replace:a }}', ['text' => 'xax']))->toBe('xx');
    expect(engine()->render('{{ text | starts_with:ab }}', ['text' => 'abc']))->toBe('true');
    expect(engine()->render('{{ text | ends_with:bc }}', ['text' => 'abc']))->toBe('true');
    expect(engine()->render('{{ text | contains:b }}', ['text' => 'abc']))->toBe('true');
    expect(engine()->render('{{ stamp | format:Y }}', ['stamp' => 0]))->toBe('1970');
    expect(engine()->render('{{ text | pad:5:0:left }}', ['text' => 'x']))->toBe('0000x');
});

it('keeps the literal when the data has a variable of the same name', function (): void {
    expect(engine()->render('{{ text | surround:x }}', ['text' => 'mid', 'x' => '!']))->toBe('xmidx');
    expect(engine()->render('{{ stamp | format:Y }}', ['stamp' => 0, 'Y' => 'd']))->toBe('1970');
    expect(engine()->render('{{ rows | pluck:name | join:"," }}', [
        'rows' => [['name' => 'a'], ['name' => 'b']],
        'name' => 'nope',
    ]))->toBe('a,b');
});

it('reads a direction after a colon instead of dropping it', function (): void {
    $items = [['price' => 10], ['price' => 2], ['price' => 7]];

    expect(engine()->render('{{ items | sort:price | first }}', ['items' => $items]))->toBe('2');
    expect(engine()->render('{{ items | sort:price:desc | first }}', ['items' => $items]))->toBe('10');
});

it('reads a number after a colon as a numeric literal', function (): void {
    expect(engine()->render('{{ text | truncate:3 }}', ['text' => 'abcdefgh']))->toBe('abc...');
    expect(engine()->render('{{ text | pad:5 }}', ['text' => 'x']))->toBe('x    ');
});

it('substitutes a value only for a parameter written with a dollar sign', function (): void {
    expect(engine()->render('{{ text | surround:$x }}', ['text' => 'mid', 'x' => '!']))->toBe('!mid!');
    expect(engine()->render('{{ text | truncate:$limit:$suffix }}', [
        'text'   => 'hello',
        'limit'  => 3,
        'suffix' => '!',
    ]))->toBe('hel!');
    expect(engine()->render('{{ rows | pluck:$field | join:"," }}', [
        'rows'  => [['name' => 'a'], ['name' => 'b']],
        'field' => 'name',
    ]))->toBe('a,b');
    expect(engine()->render('{{ text | truncate:$sizes[0] }}', [
        'text'  => 'hello',
        'sizes' => [4],
    ]))->toBe('hell...');
});

it('reads a bare word inside parentheses as a variable, unlike the colon form', function (): void {
    expect(engine()->render('{{ text | replace(a, b) }}', [
        'text' => 'xax',
        'a'    => 'a',
        'b'    => 'z',
    ]))->toBe('xzx');
    expect(engine()->render('{{ text | replace(a, b) }}', ['text' => 'xax']))->toBe('xax');
    expect(engine()->render('{{ text | replace("a", "b") }}', ['text' => 'xax']))->toBe('xbx');
});

it('rejects a colon parameter that is not a single word', function (): void {
    expect(fn(): string => engine()->render('{{ text | replace:"a","b" }}', ['text' => 'x']))
        ->toThrow(AntlersSyntaxException::class, 'Unexpected ","');
    expect(fn(): string => engine()->render('{{ stamp | format:Y-m-d }}', ['stamp' => 0]))
        ->toThrow(AntlersSyntaxException::class, 'Unexpected "-"');
    expect(fn(): string => engine()->render('{{ stamp | format:Y.m.d }}', ['stamp' => 0]))
        ->toThrow(AntlersSyntaxException::class, 'Unexpected "."');
});
