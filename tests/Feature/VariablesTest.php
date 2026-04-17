<?php

declare(strict_types=1);

it('renders a simple variable', function (): void {
    expect(engine()->render('Hello, {{ name }}!', ['name' => 'World']))->toBe('Hello, World!');
});

it('renders dot notation path', function (): void {
    expect(engine()->render('{{ user.name }}', ['user' => ['name' => 'Alice']]))->toBe('Alice');
});

it('renders deeply nested dot path', function (): void {
    $data = ['a' => ['b' => ['c' => 'deep']]];
    expect(engine()->render('{{ a.b.c }}', $data))->toBe('deep');
});

it('renders undefined variable as empty string', function (): void {
    expect(engine()->render('{{ missing }}'))->toBe('');
});

it('renders literal text unchanged', function (): void {
    expect(engine()->render('Hello World'))->toBe('Hello World');
});

it('renders null coalesce with default', function (): void {
    expect(engine()->render('{{ missing ?? "default" }}'))->toBe('default');
});

it('renders null coalesce returning existing value', function (): void {
    expect(engine()->render('{{ name ?? "Guest" }}', ['name' => 'Bob']))->toBe('Bob');
});

it('renders ternary truthy branch', function (): void {
    expect(engine()->render('{{ logged_in ? "Hi" : "Login" }}', ['logged_in' => true]))->toBe('Hi');
});

it('renders ternary falsy branch', function (): void {
    expect(engine()->render('{{ logged_in ? "Hi" : "Login" }}', ['logged_in' => false]))->toBe('Login');
});

it('renders arithmetic expression', function (): void {
    expect(engine()->render('{{ 2 + 3 }}'))->toBe('5');
});

it('renders arithmetic with variable', function (): void {
    expect(engine()->render('{{ price * 1.2 }}', ['price' => 10]))->toBe('12');
});

it('renders string concatenation with dot operator', function (): void {
    expect(engine()->render('{{ "Hello" . " " . "World" }}'))->toBe('Hello World');
});

it('renders boolean true as string', function (): void {
    expect(engine()->render('{{ true }}'))->toBe('true');
});

it('renders comments as empty', function (): void {
    expect(engine()->render('Hello {{# this is a comment #}} World'))->toBe('Hello  World');
});

it('renders escaped antlers as literal', function (): void {
    expect(engine()->render('@{{ name }}', ['name' => 'Alice']))->toBe('{{ name }}');
});

it('renders noparse blocks as raw literal content', function (): void {
    expect(engine()->render('{{ noparse }}{{ name }}{{ /noparse }}', ['name' => 'Alice']))->toBe('{{ name }}');
});

it('supports nested noparse blocks without parsing inner tags', function (): void {
    $tpl = '{{ noparse }}before {{ noparse }}{{ name }}{{ /noparse }} after{{ /noparse }}';

    expect(engine()->render($tpl, ['name' => 'Alice']))->toBe('before {{ noparse }}{{ name }}{{ /noparse }} after');
});

it('supports noparse blocks adjacent to rendered tags', function (): void {
    $tpl = '{{ noparse }}{{ name }}{{ /noparse }}|{{ name }}';

    expect(engine()->render($tpl, ['name' => 'Alice']))->toBe('{{ name }}|Alice');
});

it('renders global data', function (): void {
    $e = engine();
    $e->setGlobals(['site' => 'My Blog']);
    expect($e->render('{{ site }}'))->toBe('My Blog');
});

it('merges global and local data with local taking precedence', function (): void {
    $e = engine();
    $e->addGlobal('greeting', 'Hello');
    expect($e->render('{{ greeting }}, {{ name }}!', ['name' => 'World']))->toBe('Hello, World!');
});

it('lets local assignments shadow globals within the current render', function (): void {
    $e = engine();
    $e->addGlobal('name', 'Global');

    expect($e->render('{{ name = "Local" }}{{ name }}'))->toBe('Local');
});

it('assigns values with expression syntax without rendering them directly', function (): void {
    expect(engine()->render('{{ items = songs }}{{ items[1] }}', [
        'songs' => ['a', 'b', 'c'],
    ]))->toBe('b');
});

it('supports self-iterating assignments for iterable expressions', function (): void {
    expect(engine()->render('{{ items = songs take (2) }}{{ value }}|{{ /items }}', [
        'songs' => ['a', 'b', 'c'],
    ]))->toBe('a|b|');
});

it('supports multiple statements separated by semicolons', function (): void {
    expect(engine()->render('{{ count = 1; count + 1 }}{{ count }}'))->toBe('21');
});

it('supports semicolon-separated sub-expressions inside parentheses', function (): void {
    expect(engine()->render('{{ 2 * (count = 1; count + 2) }}{{ count }}'))->toBe('61');
});

it('forces variable resolution with dollar prefix even when a same-named tag exists', function (): void {
    $e = engine();
    $e->addTag('greet', fn(): string => 'from-tag');

    expect($e->render('{{ $greet }}', ['greet' => 'from-variable']))->toBe('from-variable');
});

it('supports explicit variable syntax for paired loops when a same-named tag exists', function (): void {
    $e = engine();
    $e->addTag('items', fn(): string => 'from-tag');

    expect($e->render('{{ $items }}{{ value }}|{{ /$items }}', [
        'items' => ['a', 'b'],
    ]))->toBe('a|b|');
});
