<?php

declare(strict_types=1);

use Bugo\Antlers\Engine;

function engineWithJson(): Engine
{
    $engine = engine();
    $engine->addModifier(
        'to_json',
        fn($v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );

    return $engine;
}

it('applies upper modifier', function (): void {
    expect(engine()->render('{{ name | upper }}', ['name' => 'hello']))
        ->toBe('HELLO');
});

it('applies upper modifier on cyrillic text', function (): void {
    expect(engine()->render('{{ name | upper }}', ['name' => 'привет']))
        ->toBe('ПРИВЕТ');
});

it('applies lower modifier', function (): void {
    expect(engine()->render('{{ name | lower }}', ['name' => 'WORLD']))
        ->toBe('world');
});

it('applies lower modifier on cyrillic text', function (): void {
    expect(engine()->render('{{ name | lower }}', ['name' => 'ПРИВЕТ']))
        ->toBe('привет');
});

it('applies ucfirst modifier', function (): void {
    expect(engine()->render('{{ name | ucfirst }}', ['name' => 'hello']))
        ->toBe('Hello');
});

it('applies ucfirst modifier on cyrillic text', function (): void {
    expect(engine()->render('{{ name | ucfirst }}', ['name' => 'привет']))
        ->toBe('Привет');
});

it('applies lcfirst modifier', function (): void {
    expect(engine()->render('{{ name | lcfirst }}', ['name' => 'Hello']))
        ->toBe('hello');
});

it('applies lcfirst modifier on cyrillic text', function (): void {
    expect(engine()->render('{{ name | lcfirst }}', ['name' => 'Привет']))
        ->toBe('привет');
});

it('applies title modifier', function (): void {
    expect(engine()->render('{{ title | title }}', ['title' => 'hello world']))
        ->toBe('Hello World');
});

it('applies trim modifier', function (): void {
    expect(engine()->render('{{ text | trim }}', ['text' => '  hello  ']))
        ->toBe('hello');
});

it('applies reverse modifier on string', function (): void {
    expect(engine()->render('{{ text | reverse }}', ['text' => 'abc']))
        ->toBe('cba');
});

it('applies reverse modifier on cyrillic text', function (): void {
    expect(engine()->render('{{ text | reverse }}', ['text' => 'привет']))
        ->toBe('тевирп');
});

it('applies reverse modifier on array', function (): void {
    expect(engineWithJson()->render('{{ items | reverse | to_json }}', ['items' => [1, 2, 3]]))
        ->toBe('[3,2,1]');
});

it('applies length modifier on string and array', function (): void {
    expect(engine()->render('{{ text | length }}', ['text' => 'hello']))->toBe('5')
        ->and(engine()->render('{{ items | length }}', ['items' => [1, 2, 3]]))->toBe('3');
});

it('applies count modifier on string and array', function (): void {
    expect(engine()->render('{{ text | count }}', ['text' => 'hello']))->toBe('5')
        ->and(engine()->render('{{ items | count }}', ['items' => [1, 2, 3]]))->toBe('3');
});

it('applies word_count modifier', function (): void {
    expect(engine()->render('{{ text | word_count }}', ['text' => 'one two three']))
        ->toBe('3');
});

it('applies word_count modifier on cyrillic text', function (): void {
    expect(engine()->render('{{ text | word_count }}', ['text' => 'раз два три']))
        ->toBe('3');
});

it('applies slugify modifier', function (): void {
    expect(engine()->render('{{ title | slugify:"_" }}', ['title' => 'Hello World']))
        ->toBe('hello_world');
});

it('applies slugify modifier on cyrillic text', function (): void {
    expect(engine()->render('{{ title | slugify }}', ['title' => 'Привет мир']))
        ->toBe('privet-mir');
});

it('applies slugify modifier on special symbols', function (): void {
    expect(engine()->render('{{ title | slugify }}', ['title' => '10% or 5€']))
        ->toBe('10-or-5eur');
});

it('applies slugify modifier on mixed scripts', function (): void {
    expect(engine()->render('{{ title | slugify }}', ['title' => 'Привет, 世界!']))
        ->toBe('privet-shi-jie');
});

it('applies snake modifier', function (): void {
    expect(engine()->render('{{ text | snake }}', ['text' => 'helloWorld']))
        ->toBe('hello_world');
});

it('applies snake modifier on cyrillic text', function (): void {
    expect(engine()->render('{{ text | snake }}', ['text' => 'Привет Мир']))
        ->toBe('привет_мир');
});

it('applies studly modifier', function (): void {
    expect(engine()->render('{{ text | studly }}', ['text' => 'hello world']))
        ->toBe('HelloWorld');
});

it('applies studly modifier on cyrillic text', function (): void {
    expect(engine()->render('{{ text | studly }}', ['text' => 'привет мир']))
        ->toBe('ПриветМир');
});

it('applies kebab modifier', function (): void {
    expect(engine()->render('{{ text | kebab }}', ['text' => 'helloWorld']))
        ->toBe('hello-world');
});

it('applies kebab modifier on cyrillic text', function (): void {
    expect(engine()->render('{{ text | kebab }}', ['text' => 'Привет Мир']))
        ->toBe('привет-мир');
});

it('applies truncate modifier', function (): void {
    expect(engine()->render('{{ text | truncate:5 }}', ['text' => 'Hello World']))
        ->toBe('Hello...');
});

it('applies truncate with custom suffix', function (): void {
    expect(engine()->render('{{ text | truncate:5:"!" }}', ['text' => 'Hello World']))
        ->toBe('Hello!');
});

it('applies limit modifier on string and array', function (): void {
    expect(engine()->render('{{ text | limit:5 }}', ['text' => 'Hello World']))
        ->toBe('Hello')
        ->and(engineWithJson()->render('{{ items | limit:2 | to_json }}', ['items' => [1, 2, 3]]))
        ->toBe('[1,2]');
});

it('applies replace modifier', function (): void {
    expect(engine()->render('{{ text | replace:"World":"Antlers" }}', ['text' => 'Hello World']))
        ->toBe('Hello Antlers');
});

it('applies regex_replace modifier', function (): void {
    expect(engine()->render('{{ text | regex_replace:"/\d+/":"#" }}', ['text' => 'Item 123']))
        ->toBe('Item #');
});

it('applies nl2br modifier', function (): void {
    expect(engine()->render('{{ text | nl2br }}', ['text' => "line1\nline2"]))
        ->toBe("line1<br />\nline2");
});

it('applies strip_tags modifier', function (): void {
    expect(engine()->render('{{ text | strip_tags }}', ['text' => '<b>Hello</b> <i>World</i>']))
        ->toBe('Hello World');
});

it('applies entities modifier', function (): void {
    expect(engine()->render('{{ text | entities }}', ['text' => '<tag attr="1">']))
        ->toBe('&lt;tag attr=&quot;1&quot;&gt;');
});

it('applies sanitize modifier', function (): void {
    expect(engine()->render('{{ text | sanitize }}', ['text' => '<tag attr="1">']))
        ->toBe('&lt;tag attr=&quot;1&quot;&gt;');
});

it('applies decode modifier', function (): void {
    expect(engine()->render('{{ text | decode }}', ['text' => '&lt;strong&gt;Hi&lt;/strong&gt;']))
        ->toBe('<strong>Hi</strong>');
});

it('applies markdown modifier', function (): void {
    expect(engine()->render('{{ text | markdown }}', ['text' => '**Bold**']))
        ->toBe('<p><strong>Bold</strong></p>');
});

it('applies wrap modifier', function (): void {
    expect(engine()->render('{{ text | wrap:"strong" }}', ['text' => 'hello']))
        ->toBe('<strong>hello</strong>');
});

it('applies surround modifier', function (): void {
    expect(engine()->render('{{ text | surround:"[":"]" }}', ['text' => 'hello']))
        ->toBe('[hello]');
});

it('applies add modifier', function (): void {
    expect(engine()->render('{{ price | add:10 }}', ['price' => 5]))
        ->toBe('15');
});

it('applies subtract modifier', function (): void {
    expect(engine()->render('{{ price | subtract:2 }}', ['price' => 5]))
        ->toBe('3');
});

it('applies multiply modifier', function (): void {
    expect(engine()->render('{{ price | multiply:2 }}', ['price' => 7]))
        ->toBe('14');
});

it('applies divide modifier', function (): void {
    expect(engine()->render('{{ price | divide:2 }}', ['price' => 8]))
        ->toBe('4');
});

it('applies mod modifier', function (): void {
    expect(engine()->render('{{ price | mod:4 }}', ['price' => 10]))
        ->toBe('2');
});

it('applies ceil modifier', function (): void {
    expect(engine()->render('{{ price | ceil }}', ['price' => 3.14]))
        ->toBe('4');
});

it('applies floor modifier', function (): void {
    expect(engine()->render('{{ price | floor }}', ['price' => 3.99]))
        ->toBe('3');
});

it('applies round modifier', function (): void {
    expect(engine()->render('{{ price | round:2 }}', ['price' => 3.14159]))
        ->toBe('3.14');
});

it('applies sort modifier', function (): void {
    expect(engineWithJson()->render('{{ items | sort | to_json }}', ['items' => [3, 1, 2]]))
        ->toBe('[1,2,3]');
});

it('applies first modifier', function (): void {
    expect(engineWithJson()->render('{{ items | first:2 | to_json }}', ['items' => [1, 2, 3]]))
        ->toBe('[1,2]');
});

it('applies last modifier', function (): void {
    expect(engineWithJson()->render('{{ items | last:2 | to_json }}', ['items' => [1, 2, 3]]))
        ->toBe('[2,3]');
});

it('applies pluck modifier', function (): void {
    expect(engineWithJson()->render('{{ items | pluck:"name" | to_json }}', [
        'items' => [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ],
    ]))->toBe('["Alice","Bob"]');
});

it('applies unique modifier', function (): void {
    expect(engineWithJson()->render('{{ items | unique | to_json }}', ['items' => [1, 1, 2, 2, 3]]))
        ->toBe('[1,2,3]');
});

it('applies flatten modifier', function (): void {
    expect(engineWithJson()->render('{{ items | flatten | to_json }}', ['items' => [1, [2, [3, 4]]]]))
        ->toBe('[1,2,3,4]');
});

it('applies keys modifier', function (): void {
    expect(engineWithJson()->render('{{ items | keys | to_json }}', ['items' => ['name' => 'Alice', 'age' => 30]]))
        ->toBe('["name","age"]');
});

it('applies values modifier', function (): void {
    expect(engineWithJson()->render('{{ items | values | to_json }}', ['items' => ['name' => 'Alice', 'age' => 30]]))
        ->toBe('["Alice",30]');
});

it('applies where modifier', function (): void {
    expect(engineWithJson()->render('{{ items | where:"active":true | to_json }}', [
        'items' => [
            ['name' => 'Alice', 'active' => true],
            ['name' => 'Bob', 'active' => false],
        ],
    ]))->toBe('[{"name":"Alice","active":true}]');
});

it('applies chunk modifier', function (): void {
    expect(engineWithJson()->render('{{ items | chunk:2 | to_json }}', ['items' => [1, 2, 3, 4]]))
        ->toBe('[[1,2],[3,4]]');
});

it('applies join modifier', function (): void {
    expect(engine()->render('{{ items | join:" / " }}', ['items' => ['a', 'b', 'c']]))
        ->toBe('a / b / c');
});

it('applies explode modifier', function (): void {
    expect(engineWithJson()->render('{{ text | explode:"-" | to_json }}', ['text' => 'a-b-c']))
        ->toBe('["a","b","c"]');
});

it('applies is_empty modifier', function (): void {
    expect(engine()->render('{{ text | is_empty }}', ['text' => '']))->toBe('true')
        ->and(engine()->render('{{ text | is_empty }}', ['text' => 'hello']))->toBe('false');
});

it('applies is_array modifier', function (): void {
    expect(engine()->render('{{ value | is_array }}', ['value' => [1, 2, 3]]))
        ->toBe('true');
});

it('applies is_numeric modifier', function (): void {
    expect(engine()->render('{{ value | is_numeric }}', ['value' => '123']))
        ->toBe('true');
});

it('applies md5 modifier', function (): void {
    expect(engine()->render('{{ text | md5 }}', ['text' => 'hello']))
        ->toBe('5d41402abc4b2a76b9719d911017c592');
});

it('applies format modifier', function (): void {
    expect(engine()->render('{{ value | format:"Y-m-d" }}', ['value' => '2024-04-01']))
        ->toBe('2024-04-01');
});

it('applies starts_with modifier', function (): void {
    expect(engine()->render('{{ text | starts_with:"Hello" }}', ['text' => 'Hello World']))
        ->toBe('true');
});

it('applies ends_with modifier', function (): void {
    expect(engine()->render('{{ text | ends_with:"World" }}', ['text' => 'Hello World']))
        ->toBe('true');
});

it('applies contains modifier', function (): void {
    expect(engine()->render('{{ text | contains:"lo Wo" }}', ['text' => 'Hello World']))
        ->toBe('true');
});

it('applies repeat modifier', function (): void {
    expect(engine()->render('{{ text | repeat:3 }}', ['text' => 'ha']))
        ->toBe('hahaha');
});

it('applies pad modifier', function (): void {
    expect(engine()->render('{{ text | pad:5:"0":"left" }}', ['text' => '7']))->toBe('00007')
        ->and(engine()->render('{{ text | pad:5:"0":"both" }}', ['text' => '7']))->toBe('00700');
});

it('chains multiple modifiers', function (): void {
    expect(engine()->render('{{ name | upper | truncate:3 }}', ['name' => 'hello']))
        ->toBe('HEL...');
});

it('supports modifier arguments in parenthesis form', function (): void {
    expect(engine()->render('{{ name | truncate(3, "!") }}', ['name' => 'hello']))
        ->toBe('hel!');
});

it('allows registering custom modifier', function (): void {
    $e = engine();
    $e->addModifier('shout', fn($v): string => strtoupper((string) $v) . '!!!');

    expect($e->render('{{ name | shout }}', ['name' => 'hello']))->toBe('HELLO!!!');
});
