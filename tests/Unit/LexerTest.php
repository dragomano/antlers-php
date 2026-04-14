<?php

declare(strict_types=1);

use Bugo\Antlers\Parser\Lexer;
use Bugo\Antlers\Parser\Token;
use Bugo\Antlers\Parser\TokenType;

describe('Lexer', function (): void {
    beforeEach(function (): void {
        $this->lexer = new Lexer();
    });

    it('tokenizes a simple identifier', function (): void {
        $tokens = $this->lexer->tokenize('name');
        expect($tokens[0]->type)->toBe(TokenType::Identifier)
            ->and($tokens[0]->value)->toBe('name');
    });

    it('tokenizes dot notation path', function (): void {
        $tokens = $this->lexer->tokenize('user.name');
        expect($tokens)->toHaveCount(4) // identifier, dot, identifier, eof
            ->and($tokens[0]->type)->toBe(TokenType::Identifier)
            ->and($tokens[1]->type)->toBe(TokenType::Dot)
            ->and($tokens[2]->type)->toBe(TokenType::Identifier);
    });

    it('tokenizes pipe and modifier', function (): void {
        $tokens = $this->lexer->tokenize('name | upper');
        $types  = array_map(fn(Token $t): TokenType => $t->type, $tokens);
        expect($types)->toContain(TokenType::Pipe)
            ->and($types)->toContain(TokenType::Identifier);
    });

    it('tokenizes arithmetic expression', function (): void {
        $tokens = $this->lexer->tokenize('price * 1.2');
        $types  = array_map(fn(Token $t): TokenType => $t->type, $tokens);
        expect($types)->toContain(TokenType::Star)
            ->and($types)->toContain(TokenType::Number);
    });

    it('tokenizes comparison operators', function (): void {
        expect($this->lexer->tokenize('==')[0]->type)->toBe(TokenType::EqEq)
            ->and($this->lexer->tokenize('!=')[0]->type)->toBe(TokenType::NotEq)
            ->and($this->lexer->tokenize('===')[0]->type)->toBe(TokenType::EqEqEq)
            ->and($this->lexer->tokenize('!==')[0]->type)->toBe(TokenType::NotEqEq)
            ->and($this->lexer->tokenize('<=')[0]->type)->toBe(TokenType::LtEq)
            ->and($this->lexer->tokenize('>=')[0]->type)->toBe(TokenType::GtEq);
    });

    it('tokenizes boolean keywords', function (): void {
        expect($this->lexer->tokenize('true')[0]->type)->toBe(TokenType::True)
            ->and($this->lexer->tokenize('false')[0]->type)->toBe(TokenType::False)
            ->and($this->lexer->tokenize('null')[0]->type)->toBe(TokenType::Null);
    });

    it('tokenizes logical keywords', function (): void {
        expect($this->lexer->tokenize('and')[0]->type)->toBe(TokenType::And)
            ->and($this->lexer->tokenize('or')[0]->type)->toBe(TokenType::Or)
            ->and($this->lexer->tokenize('not')[0]->type)->toBe(TokenType::Not);
    });

    it('tokenizes null coalesce operator', function (): void {
        expect($this->lexer->tokenize('??')[0]->type)->toBe(TokenType::QQ);
    });

    it('tokenizes gatekeeper operator', function (): void {
        expect($this->lexer->tokenize('?=')[0]->type)->toBe(TokenType::QEquals);
    });

    it('tokenizes string literals', function (): void {
        $tokens = $this->lexer->tokenize('"hello world"');
        expect($tokens[0]->type)->toBe(TokenType::String)
            ->and($tokens[0]->value)->toBe('hello world');
    });

    it('tokenizes single-quoted strings', function (): void {
        $tokens = $this->lexer->tokenize("'hello'");
        expect($tokens[0]->type)->toBe(TokenType::String)
            ->and($tokens[0]->value)->toBe('hello');
    });

    it('tokenizes integer numbers', function (): void {
        $tokens = $this->lexer->tokenize('42');
        expect($tokens[0]->type)->toBe(TokenType::Number)
            ->and($tokens[0]->value)->toBe('42');
    });

    it('tokenizes float numbers', function (): void {
        $tokens = $this->lexer->tokenize('3.14');
        expect($tokens[0]->type)->toBe(TokenType::Number)
            ->and($tokens[0]->value)->toBe('3.14');
    });

    it('always ends with EOF token', function (): void {
        $tokens = $this->lexer->tokenize('name');
        expect(end($tokens)->type)->toBe(TokenType::Eof);
    });
});
