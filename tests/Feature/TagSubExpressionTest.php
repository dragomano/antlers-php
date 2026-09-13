<?php

declare(strict_types=1);

use Bugo\Antlers\Engine;
use Bugo\Antlers\Exceptions\AntlersSyntaxException;
use Bugo\Antlers\Tags\AbstractTag;

function subExpressionEngine(): Engine
{
    $engine = engine();

    $engine->addTag('greet', new class extends AbstractTag {
        public function index(): string
        {
            return 'Hello' . ($this->parameters['name'] ?? '');
        }

        public function shout(): string
        {
            return strtoupper($this->index());
        }
    });

    $engine->addTag('echo', new class extends AbstractTag {
        public function index(): string
        {
            return (string) ($this->parameters['value'] ?? '');
        }
    });

    return $engine;
}

describe('Tag sub-expressions', function (): void {
    it('renders a tag written as a direct sub-expression', function (): void {
        expect(subExpressionEngine()->render('{{ {greet} }}'))->toBe('Hello');
    });

    it('renders a sub-expression touching the block delimiter', function (): void {
        expect(subExpressionEngine()->render('{{ {greet}}},{{ {greet}}}'))->toBe('Hello,Hello');
    });

    it('applies modifiers to a sub-expression', function (): void {
        expect(subExpressionEngine()->render('{{ {greet} | upper }}'))->toBe('HELLO');
    });

    it('assigns a sub-expression result to a variable', function (): void {
        expect(subExpressionEngine()->render('{{ x = {greet} }}{{ x }}'))->toBe('Hello');
    });

    it('passes parameters of a sub-expression tag', function (): void {
        expect(subExpressionEngine()->render('{{ {greet name=" World"} }}'))->toBe('Hello World');
    });

    it('resolves a method inside a sub-expression', function (): void {
        expect(subExpressionEngine()->render('{{ {greet:shout} }}'))->toBe('HELLO');
    });

    it('returns an empty string for an unknown tag in a sub-expression', function (): void {
        expect(engine()->render('{{ {missing} }}'))->toBe('');
    });

    it('evaluates a sub-expression used as a tag parameter', function (): void {
        expect(subExpressionEngine()->render('{{ echo value={greet} }}'))->toBe('Hello');
    });

    it('rejects an empty sub-expression', function (): void {
        expect(fn(): string => engine()->render('{{ {} }}'))
            ->toThrow(AntlersSyntaxException::class, 'Empty tag sub-expression "{}"');
    });
});

describe('Brace escapes', function (): void {
    it('renders escaped braces in a string as literal braces', function (): void {
        expect(engine()->render('{{ "s @{foo@} b" }}'))->toBe('s {foo} b');
    });

    it('renders escaped braces in a tag parameter as literal braces', function (): void {
        expect(subExpressionEngine()->render('{{ echo value="@{x@}" }}'))->toBe('{x}');
    });

    it('renders an escaped opening brace next to an interpolated value', function (): void {
        expect(engine()->render('{{ "a@{ {name} b" }}', ['name' => 'X']))->toBe('a{ X b');
    });

    it('keeps interpolation working alongside escaped braces', function (): void {
        expect(engine()->render('{{ "@{literal@} {name}" }}', ['name' => 'X']))->toBe('{literal} X');
    });

    it('renders escaped braces inside a sub-expression parameter', function (): void {
        expect(subExpressionEngine()->render('{{ {echo value="@{x@}"} }}'))->toBe('{x}');
    });
});
