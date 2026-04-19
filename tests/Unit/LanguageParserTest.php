<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersSyntaxException;
use Bugo\Antlers\Nodes\AntlersNode;
use Bugo\Antlers\Nodes\AssignmentNode;
use Bugo\Antlers\Nodes\BinaryOpNode;
use Bugo\Antlers\Nodes\CollectionOperationNode;
use Bugo\Antlers\Nodes\GatekeeperNode;
use Bugo\Antlers\Nodes\ModifierChainNode;
use Bugo\Antlers\Nodes\NullCoalesceNode;
use Bugo\Antlers\Nodes\SequenceNode;
use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Nodes\TernaryNode;
use Bugo\Antlers\Nodes\VariableNode;
use Bugo\Antlers\Nodes\VoidNode;
use Bugo\Antlers\Parser\LanguageParser;

describe('LanguageParser', function (): void {
    beforeEach(function (): void {
        $this->languageParser = new LanguageParser();
    });

    it('parses modifier chains after null coalescing', function (): void {
        $node = $this->languageParser->parseExpression('name ?? "guest" | upper');

        expect($node)->toBeInstanceOf(ModifierChainNode::class)
            ->and($node->value)->toBeInstanceOf(NullCoalesceNode::class)
            ->and($node->modifiers)->toHaveCount(1)
            ->and($node->modifiers[0]->name)->toBe('upper');
    });

    it('parses modifier chains inside ternary branches', function (): void {
        $node = $this->languageParser->parseExpression('logged_in ? name | upper : alt | lower');

        expect($node)->toBeInstanceOf(TernaryNode::class)
            ->and($node->trueBranch)->toBeInstanceOf(ModifierChainNode::class)
            ->and($node->falseBranch)->toBeInstanceOf(ModifierChainNode::class)
            ->and($node->trueBranch->modifiers[0]->name)->toBe('upper')
            ->and($node->falseBranch->modifiers[0]->name)->toBe('lower');
    });

    it('parses gatekeeper expressions with modifier chains on the right-hand side', function (): void {
        $node = $this->languageParser->parseExpression('show_bio ?= author.bio | upper');

        expect($node)->toBeInstanceOf(GatekeeperNode::class)
            ->and($node->right)->toBeInstanceOf(ModifierChainNode::class)
            ->and($node->right->modifiers[0]->name)->toBe('upper');
    });

    it('parses official collection operator syntax with parentheses', function (): void {
        $node = $this->languageParser->parseExpression('items where (active == true) take (2)');

        expect($node)->toBeInstanceOf(CollectionOperationNode::class)
            ->and($node->operators)->toHaveCount(2)
            ->and($node->operators[0]->name)->toBe('where')
            ->and($node->operators[1]->name)->toBe('take');
    });

    it('keeps arithmetic inside collection operator arguments', function (): void {
        $node = $this->languageParser->parseExpression('items take (1 + 1)');

        expect($node)->toBeInstanceOf(CollectionOperationNode::class)
            ->and($node->operators[0]->name)->toBe('take')
            ->and($node->operators[0]->arguments[0])->toBeInstanceOf(BinaryOpNode::class)
            ->and($node->operators[0]->arguments[0]->operator)->toBe('+');
    });

    it('parses modifier arguments in parenthesis form', function (): void {
        $node = $this->languageParser->parseExpression('title | truncate(3, "!")');

        expect($node)->toBeInstanceOf(ModifierChainNode::class)
            ->and($node->modifiers[0]->name)->toBe('truncate')
            ->and($node->modifiers[0]->params)->toHaveCount(2);
    });

    it('parses assignments after collection and modifier expressions', function (): void {
        $node = $this->languageParser->parseExpression('items = songs take (2) | reverse');

        expect($node)->toBeInstanceOf(AssignmentNode::class)
            ->and($node->variableName)->toBe('items')
            ->and($node->value)->toBeInstanceOf(ModifierChainNode::class)
            ->and($node->value->value)->toBeInstanceOf(CollectionOperationNode::class);
    });

    it('parses semicolon-separated statements into a sequence node', function (): void {
        $node = $this->languageParser->parseExpression('items = songs; items | reverse');

        expect($node)->toBeInstanceOf(SequenceNode::class)
            ->and($node->statements)->toHaveCount(2)
            ->and($node->statements[0])->toBeInstanceOf(AssignmentNode::class)
            ->and($node->statements[1])->toBeInstanceOf(ModifierChainNode::class);
    });

    it('parses explicit variable syntax with colon paths', function (): void {
        $node = $this->languageParser->parseExpression('$user:profile:name');

        expect($node)->toBeInstanceOf(VariableNode::class)
            ->and($node->path)->toBe('user:profile:name');
    });

    it('parses void as a dedicated literal node', function (): void {
        $node = $this->languageParser->parseExpression('void');

        expect($node)->toBeInstanceOf(VoidNode::class);
    });

    it('expands shorthand dynamic tag parameters with :$name syntax', function (): void {
        $node            = new AntlersNode();
        $node->name      = 'probe';
        $node->rawContent = 'probe :$id :$class';

        $parsed = $this->languageParser->parseNode($node);

        expect($parsed)->toBeInstanceOf(TagNode::class)
            ->and($parsed->parameters)->toHaveKeys(['id', 'class'])
            ->and($parsed->parameters['id'])->toBeInstanceOf(VariableNode::class)
            ->and($parsed->parameters['id']->path)->toBe('id')
            ->and($parsed->parameters['class'])->toBeInstanceOf(VariableNode::class)
            ->and($parsed->parameters['class']->path)->toBe('class');
    });

    it('throws a syntax exception for an invalid assignment target', function (): void {
        expect(fn() => $this->languageParser->parseExpression('count + 1 = total'))
            ->toThrow(AntlersSyntaxException::class, 'Assignment target must be a variable path');
    });

    it('throws a syntax exception for an unterminated ternary expression', function (): void {
        expect(fn() => $this->languageParser->parseExpression('logged_in ? "yes"'))
            ->toThrow(AntlersSyntaxException::class, 'Unterminated ternary expression');
    });

    it('throws a syntax exception for an empty statement before a terminator', function (): void {
        expect(fn() => $this->languageParser->parseExpression('; count'))
            ->toThrow(AntlersSyntaxException::class, 'Unexpected token [T_SEMICOLON:;] in expression');
    });

    it('throws a syntax exception when an explicit variable path is incomplete', function (): void {
        expect(fn() => $this->languageParser->parseExpression('$user:'))
            ->toThrow(AntlersSyntaxException::class, 'Expected identifier after T_COLON in variable path');
    });
});
