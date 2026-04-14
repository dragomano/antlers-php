<?php

declare(strict_types=1);

use Bugo\Antlers\Nodes\AssignmentNode;
use Bugo\Antlers\Nodes\BinaryOpNode;
use Bugo\Antlers\Nodes\CollectionOperationNode;
use Bugo\Antlers\Nodes\GatekeeperNode;
use Bugo\Antlers\Nodes\ModifierChainNode;
use Bugo\Antlers\Nodes\NullCoalesceNode;
use Bugo\Antlers\Nodes\SequenceNode;
use Bugo\Antlers\Nodes\TernaryNode;
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
});
