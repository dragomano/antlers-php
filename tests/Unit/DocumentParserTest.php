<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersSyntaxException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\AntlersNode;
use Bugo\Antlers\Nodes\LiteralNode;
use Bugo\Antlers\Parser\DocumentParser;

describe('DocumentParser', function (): void {
    beforeEach(function (): void {
        $this->parser = new DocumentParser();
    });

    it('returns single literal for plain text', function (): void {
        $nodes = $this->parser->parse('Hello World');
        expect($nodes)->toHaveCount(1)
            ->and($nodes[0])->toBeInstanceOf(LiteralNode::class)
            ->and($nodes[0]->content)->toBe('Hello World');
    });

    it('splits literal and antlers nodes', function (): void {
        $nodes = $this->parser->parse('Hello {{ name }}!');
        expect($nodes)->toHaveCount(3)
            ->and($nodes[0])->toBeInstanceOf(LiteralNode::class)
            ->and($nodes[1])->toBeInstanceOf(AntlersNode::class)
            ->and($nodes[2])->toBeInstanceOf(LiteralNode::class)
            ->and($nodes[0]->content)->toBe('Hello ')
            ->and($nodes[1]->rawContent)->toBe('name')
            ->and($nodes[2]->content)->toBe('!');
    });

    it('strips comments from output', function (): void {
        $nodes = $this->parser->parse('Hello {{# comment #}} World');
        // Comment is stripped — only literals remain
        $literals = array_filter($nodes, fn(AbstractNode $n): bool => $n instanceof LiteralNode);
        expect(implode('', array_map(fn(LiteralNode $n): string => $n->content, $literals)))
            ->toBe('Hello  World');
    });

    it('identifies closing tags via closingPair on the opening node', function (): void {
        $nodes = $this->parser->parse('{{ if true }}yes{{ /if }}');
        expect($nodes)->toHaveCount(1);

        $ifNode = $nodes[0];
        expect($ifNode)->toBeInstanceOf(AntlersNode::class)
            ->and($ifNode->name)->toBe('if')
            ->and($ifNode->closingPair)->not->toBeNull()
            ->and($ifNode->closingPair->isClosingTag)->toBeTrue()
            ->and($ifNode->closingPair->name)->toBe('if');
    });

    it('pairs if/endif block', function (): void {
        $nodes = $this->parser->parse('{{ if cond }}yes{{ /if }}');
        expect($nodes)->toHaveCount(1);

        $ifNode = $nodes[0];
        expect($ifNode)->toBeInstanceOf(AntlersNode::class)
            ->and($ifNode->name)->toBe('if')
            ->and($ifNode->children)->toHaveCount(1)
            ->and($ifNode->children[0])->toBeInstanceOf(LiteralNode::class)
            ->and($ifNode->children[0]->content)->toBe('yes');
    });

    it('pairs foreach block', function (): void {
        $nodes = $this->parser->parse('{{ foreach items as item }}{{ item }}{{ /foreach }}');
        expect($nodes)->toHaveCount(1);

        $loop = $nodes[0];
        expect($loop)->toBeInstanceOf(AntlersNode::class)
            ->and($loop->name)->toBe('foreach')
            ->and($loop->children)->toHaveCount(1);
    });

    it('handles escaped antlers as literal', function (): void {
        $nodes    = $this->parser->parse('@{{ raw }}');
        $literals = array_filter($nodes, fn(AbstractNode $n): bool => $n instanceof LiteralNode);
        $content  = implode('', array_map(fn(LiteralNode $n): string => $n->content, $literals));
        expect($content)->toContain('{{ raw }}');
    });

    it('ignores empty antlers blocks', function (): void {
        expect($this->parser->parse('{{   }}'))->toBe([]);
    });

    it('ignores orphaned closing tags at the root level', function (): void {
        $nodes = $this->parser->parse('{{ /if }}Hello');

        expect($nodes)->toHaveCount(1)
            ->and($nodes[0])->toBeInstanceOf(LiteralNode::class)
            ->and($nodes[0]->content)->toBe('Hello');
    });

    it('throws a syntax exception for an unclosed antlers tag with the source line', function (): void {
        try {
            $this->parser->parse("Before\n{{ name");
            $this->fail('Expected AntlersSyntaxException was not thrown.');
        } catch (AntlersSyntaxException $e) {
            expect($e->getMessage())->toBe('Unclosed Antlers tag {{')
                ->and($e->templateLine)->toBe(2);
        }
    });

    it('throws a syntax exception for an unclosed comment with the source line', function (): void {
        try {
            $this->parser->parse("Before\n{{# comment");
            $this->fail('Expected AntlersSyntaxException was not thrown.');
        } catch (AntlersSyntaxException $e) {
            expect($e->getMessage())->toBe('Unclosed Antlers comment {{#')
                ->and($e->templateLine)->toBe(2);
        }
    });

    it('throws a syntax exception for unclosed escaped antlers with the source line', function (): void {
        try {
            $this->parser->parse("Before\n@{{ raw");
            $this->fail('Expected AntlersSyntaxException was not thrown.');
        } catch (AntlersSyntaxException $e) {
            expect($e->getMessage())->toBe('Unclosed escaped antlers @{{')
                ->and($e->templateLine)->toBe(2);
        }
    });

    it('throws a syntax exception for an unclosed noparse block with the opening line', function (): void {
        try {
            $this->parser->parse("Before\n{{ noparse }}raw text");
            $this->fail('Expected AntlersSyntaxException was not thrown.');
        } catch (AntlersSyntaxException $e) {
            expect($e->getMessage())->toBe('Unclosed noparse block {{ noparse }}')
                ->and($e->templateLine)->toBe(2);
        }
    });

    it('throws a syntax exception when a nested tag inside noparse is missing its closing delimiter', function (): void {
        try {
            $this->parser->parse('{{ noparse }}before {{ name');
            $this->fail('Expected AntlersSyntaxException was not thrown.');
        } catch (AntlersSyntaxException $e) {
            expect($e->getMessage())->toBe('Unclosed Antlers tag {{')
                ->and($e->templateLine)->toBe(1);
        }
    });

    it('throws a syntax exception when noparse reaches end of template after a nested tag', function (): void {
        try {
            $this->parser->parse('{{ noparse }}{{ name }}');
            $this->fail('Expected AntlersSyntaxException was not thrown.');
        } catch (AntlersSyntaxException $e) {
            expect($e->getMessage())->toBe('Unclosed noparse block {{ noparse }}')
                ->and($e->templateLine)->toBe(1);
        }
    });
});
