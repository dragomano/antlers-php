<?php

declare(strict_types=1);

use Bugo\Antlers\Nodes\ModifierChainNode;
use Bugo\Antlers\Nodes\NullCoalesceNode;
use Bugo\Antlers\Nodes\TernaryNode;
use Bugo\Antlers\Parser\LanguageParser;

describe('LanguageParser', function () {
    beforeEach(function () {
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
});
