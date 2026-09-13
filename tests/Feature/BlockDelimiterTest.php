<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersSyntaxException;
use Bugo\Antlers\Nodes\AntlersNode;
use Bugo\Antlers\Parser\DocumentParser;
use Bugo\Antlers\Tags\AbstractTag;

it('keeps quoted delimiters and braces inside ordinary tag parameters', function (string $value): void {
    $engine = engine();
    $engine->addTag('box', new class extends AbstractTag {
        public function index(): string
        {
            return $this->parameters['value'] ?? '';
        }
    });

    expect($engine->render('{{ box value=' . $value . ' }}tail'))->toBe('before }} { aftertail');
})->with([
    'double quotes' => ['"before }} { after"'],
    'single quotes' => ["'before }} { after'"],
]);

it('scans complete block contents before the outer delimiter', function (string $content): void {
    $nodes = (new DocumentParser())->parse('{{ ' . $content . '}}tail');

    expect($nodes)->toHaveCount(2)
        ->and($nodes[0])->toBeInstanceOf(AntlersNode::class)
        ->and($nodes[0]->rawContent)->toBe($content)
        ->and($nodes[1]->content)->toBe('tail');
})->with([
    'ordinary variable'     => ['name'],
    'adjacent single brace' => ['{box}'],
    'nested single braces'  => ['{box value={increment}}'],
    'quoted nested braces'  => ['{box value="}} {"}'],
    'escaped double quote'  => ['box value="before \\" }} after"'],
    'escaped single quote'  => ["box value='before \\' }} after'"],
    'escaped backslash'     => ['box value="before \\\\"'],
    'opposite quote'        => ["box value=\"before ' }} after\""],
]);

it('renders a subexpression whose close touches the block delimiter', function (): void {
    expect(engine()->render('{{ {increment}}},{{ {increment}}}'))->toBe('1,2');
});

it('counts lines through quoted delimiters and nested braces', function (): void {
    $nodes = (new DocumentParser())->parse("{{\n box value=\"first }}\n{ second\"\n}}{{\n {increment}\n}}");

    expect($nodes)->toHaveCount(2)
        ->and($nodes[0]->line)->toBe(2)
        ->and($nodes[1]->line)->toBe(5);
});

it('rejects unclosed blocks at their opening line', function (string $block, string $message = 'Unclosed Antlers tag "{{"'): void {
    expect(fn(): array => (new DocumentParser())->parse("first\nsecond\n" . $block))
        ->toThrow(AntlersSyntaxException::class, $message . ' on line 3');
})->with([
    'missing delimiter'      => ['{{ box'],
    'single closing brace'   => ['{{ box }'],
    'missing outer brace'    => ['{{ {box}}'],
    'unclosed nested braces' => ['{{ {box value={increment}}'],
    'unclosed double quote'  => ['{{ box value="before }}', 'Unterminated string'],
    'unclosed single quote'  => ["{{ box value='before }}", 'Unterminated string'],
    'escaped closing quote'  => ['{{ box value="before \\" }}', 'Unterminated string'],
    'trailing backslash'     => ['{{ box value="before \\', 'Unterminated string'],
    'multiline quoted value' => ["{{ box value=\"before\n}}", 'Unterminated string'],
]);

it('preserves escaped and noparse delimiter handling', function (string $template, string $expected): void {
    expect(engine()->render($template))->toBe($expected);
})->with([
    'escaped' => ['@{{ box value="}}" }}', '{{ box value="}}" }}'],
    'noparse' => ['{{ noparse }}{{ box value="}}" }}{{ /noparse }}', '{{ box value="}}" }}'],
]);
