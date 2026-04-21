<?php

declare(strict_types=1);

use Bugo\Antlers\Exceptions\AntlersSyntaxException;
use Bugo\Antlers\Nodes\AntlersNode;
use Bugo\Antlers\Nodes\ArrayNode;
use Bugo\Antlers\Nodes\AssignmentNode;
use Bugo\Antlers\Nodes\BinaryOpNode;
use Bugo\Antlers\Nodes\BooleanNode;
use Bugo\Antlers\Nodes\CollectionOperationNode;
use Bugo\Antlers\Nodes\GatekeeperNode;
use Bugo\Antlers\Nodes\LoopNode;
use Bugo\Antlers\Nodes\ModifierChainNode;
use Bugo\Antlers\Nodes\NullCoalesceNode;
use Bugo\Antlers\Nodes\SequenceNode;
use Bugo\Antlers\Nodes\SetNode;
use Bugo\Antlers\Nodes\StringValueNode;
use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Nodes\TernaryNode;
use Bugo\Antlers\Nodes\UnaryOpNode;
use Bugo\Antlers\Nodes\VariableNode;
use Bugo\Antlers\Nodes\VoidNode;
use Bugo\Antlers\Parser\LanguageParser;

function parserNode(string $name, string $raw, array $children = [], bool $closing = false): AntlersNode
{
    $node              = new AntlersNode();
    $node->name        = $name;
    $node->rawContent  = $raw;
    $node->children    = $children;
    $node->isClosingTag = $closing;

    return $node;
}

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
        $node = parserNode('probe', 'probe :$id :$class');

        $parsed = $this->languageParser->parseNode($node);

        expect($parsed)->toBeInstanceOf(TagNode::class)
            ->and($parsed->parameters)->toHaveKeys(['id', 'class'])
            ->and($parsed->parameters['id'])->toBeInstanceOf(VariableNode::class)
            ->and($parsed->parameters['id']->path)->toBe('id')
            ->and($parsed->parameters['class'])->toBeInstanceOf(VariableNode::class)
            ->and($parsed->parameters['class']->path)->toBe('class');
    });

    it('parses special antlers nodes and set or loop syntax branches', function (): void {
        $closing = parserNode('if', 'if logged_in', closing: true);
        $noparse = parserNode('noparse', 'noparse', [new AntlersNode()]);
        $setNode = parserNode('set', 'set total = count + 1');

        $parsedSet = $this->languageParser->parseNode($setNode);

        expect($this->languageParser->parseNode($closing))->toBe($closing)
            ->and($this->languageParser->parseNode($noparse))->toBe($noparse)
            ->and($parsedSet)->toBeInstanceOf(SetNode::class)
            ->and($parsedSet->variableName)->toBe('total')
            ->and($parsedSet->value)->toBeInstanceOf(BinaryOpNode::class)
            ->and($this->languageParser->parseNode(parserNode('for', 'for 1 to 3')))
            ->toBeInstanceOf(LoopNode::class)
            ->and(fn() => $this->languageParser->parseNode(parserNode('for', 'for nope')))
            ->toThrow(AntlersSyntaxException::class, 'Invalid for syntax: {{ for nope }}')
            ->and(fn() => $this->languageParser->parseNode(parserNode('set', 'set total')))
            ->toThrow(AntlersSyntaxException::class, 'Invalid set syntax: {{ set total }}');
    });

    it('parses tag parameters across whitespace, escaping and dynamic values', function (): void {
        $tag = $this->languageParser->parseNode(parserNode(
            'probe',
            '%probe key   =   "a\\b" flag :expr="1 + 2" :path="user.name"   ',
        ));

        $invalidDynamic = $this->languageParser->parseNode(parserNode('probe', '%probe :$'));
        $invalidKey     = $this->languageParser->parseNode(parserNode('probe', '%probe =value'));

        expect($tag)->toBeInstanceOf(TagNode::class)
            ->and(array_keys($tag->parameters))->toBe(['key', 'flag', 'expr', 'path'])
            ->and($tag->parameters['key'])->toBeInstanceOf(StringValueNode::class)
            ->and($tag->parameters['key']->value)->toBe('a\\b')
            ->and($tag->parameters['flag'])->toBeInstanceOf(BooleanNode::class)
            ->and($tag->parameters['expr'])->toBeInstanceOf(BinaryOpNode::class)
            ->and($tag->parameters['path'])->toBeInstanceOf(VariableNode::class)
            ->and($invalidDynamic)->toBeInstanceOf(TagNode::class)
            ->and($invalidDynamic->parameters)->toBe([])
            ->and($invalidKey)->toBeInstanceOf(TagNode::class)
            ->and($invalidKey->parameters)->toBe([]);
    });

    it('handles statement separators and malformed trailing tokens', function (): void {
        $extraSemicolons = $this->languageParser->parseExpression('name;;; other');
        $trailingSemicolon = $this->languageParser->parseExpression('name;');

        expect($extraSemicolons)->toBeInstanceOf(SequenceNode::class)
            ->and($extraSemicolons->statements)->toHaveCount(2)
            ->and($trailingSemicolon)->toBeInstanceOf(VariableNode::class)
            ->and(fn() => $this->languageParser->parseExpression('()'))
            ->toThrow(AntlersSyntaxException::class, 'Expected expression before statement terminator')
            ->and(fn() => $this->languageParser->parseExpression('name )'))
            ->toThrow(AntlersSyntaxException::class, "Unexpected token T_RPAREN (')') in expression")
            ->and(fn() => $this->languageParser->parseExpression('$items[0)'))
            ->toThrow(AntlersSyntaxException::class, "Expected T_RBRACKET but got T_RPAREN (')')");
    });

    it('validates collection operators and modifier parsing edge cases', function (): void {
        expect(fn() => $this->languageParser->parseExpression('items where (=> active)'))
            ->toThrow(AntlersSyntaxException::class, 'Expected identifier before => in where operator')
            ->and(fn() => $this->languageParser->parseExpression('items where (1 => active)'))
            ->toThrow(AntlersSyntaxException::class, 'Expected identifier before => in where operator')
            ->and(fn() => $this->languageParser->parseExpression('items take (1, 2)'))
            ->toThrow(AntlersSyntaxException::class, 'Expected a single parenthesized expression')
            ->and(fn() => $this->languageParser->parseExpression('items groupby (field foo bar)'))
            ->toThrow(AntlersSyntaxException::class, 'Invalid groupby alias')
            ->and(fn() => $this->languageParser->parseExpression('items groupby (field) as 1'))
            ->toThrow(AntlersSyntaxException::class, 'Expected collection alias name')
            ->and(fn() => $this->languageParser->parseExpression('name | 1'))
            ->toThrow(AntlersSyntaxException::class, "Expected modifier name but got T_NUMBER ('1')");
    });

    it('parses nested ternaries arrays explicit variables and parenthesized groups', function (): void {
        $ternary = $this->languageParser->parseExpression('a ? (b ? [c] : d) : e');
        $array   = $this->languageParser->parseExpression('[]');
        $path    = $this->languageParser->parseExpression('$items[0]');
        $unary   = $this->languageParser->parseExpression('-count');
        $dotted  = $this->languageParser->parseExpression('user.');
        $wrapped = $this->languageParser->parseExpression('title | wrap((1 + 2), [item])');

        expect($ternary)->toBeInstanceOf(TernaryNode::class)
            ->and($ternary->trueBranch)->toBeInstanceOf(TernaryNode::class)
            ->and($ternary->trueBranch->trueBranch)->toBeInstanceOf(ArrayNode::class)
            ->and($array)->toBeInstanceOf(ArrayNode::class)
            ->and($array->items)->toBe([])
            ->and($path)->toBeInstanceOf(VariableNode::class)
            ->and($path->path)->toBe('items[0]')
            ->and($unary)->toBeInstanceOf(UnaryOpNode::class)
            ->and($unary->operator)->toBe('-')
            ->and($dotted)->toBeInstanceOf(VariableNode::class)
            ->and($dotted->path)->toBe('user')
            ->and($wrapped)->toBeInstanceOf(ModifierChainNode::class)
            ->and($wrapped->modifiers[0]->params)->toHaveCount(2)
            ->and(fn() => $this->languageParser->parseExpression('title | wrap('))
            ->toThrow(AntlersSyntaxException::class, 'Unterminated parenthesized expression');
    });

    it('parses interpolated strings and known non-tag syntax branches', function (): void {
        $interpolated = $this->languageParser->parseExpression('"Hello {name}!"');
        $unclosedTail = $this->languageParser->parseExpression('"Hi {name} {"');

        expect($interpolated)->toBeInstanceOf(StringValueNode::class)
            ->and($interpolated->parts)->toHaveCount(3)
            ->and($interpolated->parts[0])->toBe('Hello ')
            ->and($interpolated->parts[1])->toBeInstanceOf(VariableNode::class)
            ->and($interpolated->parts[2])->toBe('!')
            ->and($unclosedTail)->toBeInstanceOf(StringValueNode::class)
            ->and($unclosedTail->parts[0])->toBe('Hi ')
            ->and($unclosedTail->parts[1])->toBeInstanceOf(VariableNode::class)
            ->and($unclosedTail->parts[2])->toBe(' ')
            ->and($unclosedTail->parts[3])->toBe('{')
            ->and(fn() => $this->languageParser->parseNode(parserNode('cache', 'cache key="home"')))
            ->toThrow(AntlersSyntaxException::class, "Unexpected token T_IDENTIFIER ('key') in expression");
    });

    it('handles trailing tag whitespace and nested ternary branch token slicing', function (): void {
        $tag = $this->languageParser->parseNode(parserNode('probe', '%probe flag   '));
        $ternary = $this->languageParser->parseExpression('a ? b ? c : d : e');

        expect($tag)->toBeInstanceOf(TagNode::class)
            ->and(array_keys($tag->parameters))->toBe(['flag'])
            ->and($ternary)->toBeInstanceOf(TernaryNode::class)
            ->and($ternary->condition)->toBeInstanceOf(VariableNode::class)
            ->and($ternary->condition->path)->toBe('a')
            ->and($ternary->trueBranch)->toBeInstanceOf(TernaryNode::class)
            ->and($ternary->trueBranch->condition)->toBeInstanceOf(VariableNode::class)
            ->and($ternary->trueBranch->condition->path)->toBe('b')
            ->and($ternary->trueBranch->trueBranch)->toBeInstanceOf(VariableNode::class)
            ->and($ternary->trueBranch->trueBranch->path)->toBe('c')
            ->and($ternary->trueBranch->falseBranch)->toBeInstanceOf(VariableNode::class)
            ->and($ternary->trueBranch->falseBranch->path)->toBe('d')
            ->and($ternary->falseBranch)->toBeInstanceOf(VariableNode::class)
            ->and($ternary->falseBranch->path)->toBe('e');
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
