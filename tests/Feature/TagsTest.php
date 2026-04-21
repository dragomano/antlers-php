<?php

declare(strict_types=1);

use Bugo\Antlers\Runtime\NodeProcessor;
use Bugo\Antlers\Tags\AbstractTag;

it('calls a simple callable tag', function (): void {
    $e = engine();
    $e->addTag('greet', fn($params): string => 'Hello, ' . ($params['name'] ?? 'World') . '!');
    expect($e->render('{{ greet name="Alice" }}'))->toBe('Hello, Alice!');
});

it('prefers a simple tag over a same-named variable by default', function (): void {
    $e = engine();
    $e->addTag('greet', fn(): string => 'from-tag');

    expect($e->render('{{ greet }}', ['greet' => 'from-variable']))->toBe('from-tag');
});

it('calls a namespaced tag method', function (): void {
    $e = engine();
    $e->addTag('my', fn($params, $data, $proc, $method) => match ($method) {
        'upper' => strtoupper($params['value'] ?? ''),
        'lower' => strtolower($params['value'] ?? ''),
        default => '',
    });
    expect($e->render('{{ my:upper value="hello" }}'))->toBe('HELLO')
        ->and($e->render('{{ my:lower value="WORLD" }}'))->toBe('world');
});

it('calls a paired tag with children', function (): void {
    $e = engine();
    $e->addTag('wrap', function (array $params, array $data, NodeProcessor $proc, $method, array $children): string {
        $tag     = $params['tag'] ?? 'div';
        $content = $proc->reduce($children, $data);

        return "<$tag>$content</$tag>";
    });
    expect($e->render('{{ wrap tag="p" }}Hello{{ /wrap }}'))->toBe('<p>Hello</p>');
});

it('calls a class-based tag', function (): void {
    $e = engine();
    $e->addTag('hello', new class extends AbstractTag {
        public function index(): string
        {
            return 'Hello from class tag!';
        }
    });
    expect($e->render('{{ hello }}'))->toBe('Hello from class tag!');
});

it('dispatches class-based tag methods and exposes abstract tag helpers', function (): void {
    $e = engine();
    $e->addTag('panel', new class extends AbstractTag {
        public function index(): string
        {
            return $this->param('title', 'missing') . '|' . ($this->getBool('visible', true) ? 'yes' : 'no');
        }

        public function show(): string
        {
            return $this->param('title', 'missing')
                . '|'
                . $this->content(['title' => 'Inner content'])
                . '|'
                . ($this->getBool('visible', false) ? 'yes' : 'no')
                . '|'
                . ($this->getBool('missing', true) ? 'yes' : 'no');
        }
    });

    expect($e->render('{{ panel title="Outer" visible=false }}'))->toBe('Outer|no')
        ->and($e->render('{{ panel:show title="Outer" visible=1 }}{{ title }}{{ /panel:show }}'))->toBe('Outer|Inner content|yes|yes');
});

it('unknown tag returns empty string in lenient mode', function (): void {
    expect(engine()->render('{{ unknown_tag }}'))->toBe('');
});

it('forces tag resolution with percent prefix even when a variable exists', function (): void {
    $e = engine();
    $e->addTag('greet', fn(): string => 'from-tag');

    expect($e->render('{{ %greet }}', ['greet' => 'from-variable']))->toBe('from-tag');
});

it('prefers colon-notation variables over matching tag methods when the path exists', function (): void {
    $e = engine();
    $e->addTag('user', fn($params, $data, $proc, $method): string => $method === 'profile' ? 'from-tag' : '');

    expect($e->render('{{ user:profile:name }}', [
        'user' => ['profile' => ['name' => 'Alice']],
    ]))->toBe('Alice');
});

it('omits tag parameters explicitly set to void', function (): void {
    $e = engine();
    $e->addTag('probe', fn(array $params): string => array_key_exists('class', $params) ? (string) $params['class'] : 'missing');

    expect($e->render('{{ probe class=void }}'))->toBe('missing');
});

it('omits tag parameters when string interpolation resolves to void', function (): void {
    $e = engine();
    $e->addTag('probe', fn(array $params): string => array_key_exists('class', $params) ? (string) $params['class'] : 'missing');

    expect($e->render('{{ probe class="{wide ? \'w-full\' : void}" }}', ['wide' => true]))->toBe('w-full')
        ->and($e->render('{{ probe class="{wide ? \'w-full\' : void}" }}', ['wide' => false]))->toBe('missing');
});

it('supports shorthand dynamic tag parameters with :$name syntax', function (): void {
    $e = engine();
    $e->addTag('probe', fn(array $params): string => ($params['id'] ?? 'missing') . '|' . ($params['class'] ?? 'missing'));

    expect($e->render('{{ probe :$id :$class }}', [
        'id'    => 'entry-1',
        'class' => 'hero',
    ]))->toBe('entry-1|hero');
});
