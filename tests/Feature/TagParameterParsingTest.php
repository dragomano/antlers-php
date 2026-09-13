<?php

declare(strict_types=1);

use Bugo\Antlers\Tags\AbstractTag;

it('processes escape sequences in quoted tag parameters', function (): void {
    $engine = engine();
    $engine->addTag('testbox', new class extends AbstractTag {
        public function index(): string
        {
            return $this->parameters['a'] ?? 'missing';
        }
    });

    $result = $engine->render('{{ testbox a="line1\nline2" }}');

    expect($result)->toContain("line1\nline2");
});

it('parses expressions with operators in unquoted parameter values', function (): void {
    $engine = engine();
    $engine->addTag('testbox', new class extends AbstractTag {
        public function index(): string
        {
            return (string) ($this->parameters['a'] ?? 'missing');
        }
    });

    $result = $engine->render('{{ testbox a=1+2 }}');

    expect($result)->toBe('3');
});

it('handles multiple simple parameters', function (): void {
    $engine = engine();
    $engine->addTag('testbox', new class extends AbstractTag {
        public function index(): string
        {
            $a = $this->parameters['a'] ?? 'missing';
            $b = $this->parameters['b'] ?? 'missing';

            return sprintf('a=%s,b=%s', $a, $b);
        }
    });

    $result = $engine->render('{{ testbox a=5 b=10 }}');

    expect($result)->toBe('a=5,b=10');
});

it('parses boolean flags without equals sign', function (): void {
    $engine = engine();
    $engine->addTag('testbox', new class extends AbstractTag {
        public function index(): string
        {
            $enabled = $this->parameters['enabled'] ?? false;

            return $enabled ? 'yes' : 'no';
        }
    });

    $result = $engine->render('{{ testbox enabled }}');

    expect($result)->toBe('yes');
});

it('handles quoted strings with spaces', function (): void {
    $engine = engine();
    $engine->addTag('testbox', new class extends AbstractTag {
        public function index(): string
        {
            return $this->parameters['a'] ?? 'missing';
        }
    });

    $result = $engine->render('{{ testbox a="hello world" }}');

    expect($result)->toBe('hello world');
});

it('processes all escape sequences in tag parameters', function (): void {
    $engine = engine();
    $engine->addTag('testbox', new class extends AbstractTag {
        public function index(): string
        {
            return bin2hex($this->parameters['val'] ?? '');
        }
    });

    expect($engine->render('{{ testbox val="\t" }}'))->toBe('09');
    expect($engine->render('{{ testbox val="\r" }}'))->toBe('0d');
    expect($engine->render('{{ testbox val="\\\\" }}'))->toBe('5c');
    expect($engine->render('{{ testbox val="\"" }}'))->toBe('22');
    expect($engine->render("{{ testbox val='a\\'b' }}"))->toBe('612762');
    expect($engine->render('{{ testbox val="\0" }}'))->toBe('00');
});

it('handles parentheses and brackets in unquoted parameter values', function (): void {
    $engine = engine();
    $engine->addGlobal('items', ['x' => 'value']);
    $engine->addTag('testbox', new class extends AbstractTag {
        public function index(): string
        {
            return (string) ($this->parameters['result'] ?? 'missing');
        }
    });

    // Parentheses in array access should work
    $result = $engine->render('{{ testbox result=items["x"] }}');
    expect($result)->toBe('value');
});

it('handles array access with brackets in unquoted parameter values', function (): void {
    $engine = engine();
    $engine->addGlobal('items', ['a', 'b', 'c']);
    $engine->addTag('testbox', new class extends AbstractTag {
        public function index(): string
        {
            return (string) ($this->parameters['item'] ?? 'missing');
        }
    });

    $result = $engine->render('{{ testbox item=items[1] }}');

    expect($result)->toBe('b');
});
