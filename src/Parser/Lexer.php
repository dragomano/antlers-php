<?php

declare(strict_types=1);

namespace Bugo\Antlers\Parser;

use Bugo\Antlers\Exceptions\AntlersSyntaxException;

/**
 * Stage 2: Tokenizes the raw content inside a {{ ... }} block.
 *
 * Input:  "user.name | upper:5"
 * Output: [T_IDENTIFIER(user), T_DOT, T_IDENTIFIER(name), T_PIPE, T_IDENTIFIER(upper), T_COLON, T_NUMBER(5)]
 */
final class Lexer
{
    private string $input = '';

    private int $length = 0;

    private int $pos = 0;

    /** @var Token[] */
    private array $tokens = [];

    /**
     * @return Token[]
     */
    public function tokenize(string $input): array
    {
        $this->input  = $input;
        $this->length = strlen($input);
        $this->pos    = 0;
        $this->tokens = [];

        while ($this->pos < $this->length) {
            $this->skipWhitespace();

            if ($this->pos >= $this->length) {
                break;
            }

            $ch = $this->input[$this->pos];

            // Numbers
            if (
                ctype_digit($ch)
                || (
                    $ch === '-'
                    && $this->pos + 1 < $this->length
                    && ctype_digit($this->input[$this->pos + 1])
                    && ! $this->lastIsValue()
                )
            ) {
                $this->readNumber();

                continue;
            }

            // Strings
            if ($ch === '"' || $ch === "'") {
                $this->readString($ch);

                continue;
            }

            // Identifiers and keywords
            if (ctype_alpha($ch) || $ch === '_') {
                $this->readIdentifier();

                continue;
            }

            // Multi-char operators — check longer first
            if ($this->tryRead('===')) {
                $this->add(TokenType::EqEqEq, '===');

                continue;
            }

            if ($this->tryRead('!==')) {
                $this->add(TokenType::NotEqEq, '!==');

                continue;
            }

            if ($this->tryRead('==')) {
                $this->add(TokenType::EqEq, '==');

                continue;
            }

            if ($this->tryRead('!=')) {
                $this->add(TokenType::NotEq, '!=');

                continue;
            }

            if ($this->tryRead('<=')) {
                $this->add(TokenType::LtEq, '<=');

                continue;
            }

            if ($this->tryRead('>=')) {
                $this->add(TokenType::GtEq, '>=');

                continue;
            }

            if ($this->tryRead('&&')) {
                $this->add(TokenType::And, '&&');

                continue;
            }

            if ($this->tryRead('||')) {
                $this->add(TokenType::Or, '||');

                continue;
            }

            if ($this->tryRead('??')) {
                $this->add(TokenType::QQ, '??');

                continue;
            }

            if ($this->tryRead('?=')) {
                $this->add(TokenType::QEquals, '?=');

                continue;
            }

            if ($this->tryRead('=>')) {
                $this->add(TokenType::Arrow, '=>');

                continue;
            }

            // Single-char tokens
            match ($ch) {
                '+'     => $this->add(TokenType::Plus, '+'),
                '-'     => $this->add(TokenType::Minus, '-'),
                '*'     => $this->add(TokenType::Star, '*'),
                '/'     => $this->add(TokenType::Slash, '/'),
                '%'     => $this->add(TokenType::Percent, '%'),
                '^'     => $this->add(TokenType::Caret, '^'),
                '<'     => $this->add(TokenType::Lt, '<'),
                '>'     => $this->add(TokenType::Gt, '>'),
                '!'     => $this->add(TokenType::Not, '!'),
                '='     => $this->add(TokenType::Equals, '='),
                '?'     => $this->add(TokenType::Question, '?'),
                '('     => $this->add(TokenType::LParen, '('),
                ')'     => $this->add(TokenType::RParen, ')'),
                '['     => $this->add(TokenType::LBracket, '['),
                ']'     => $this->add(TokenType::RBracket, ']'),
                ','     => $this->add(TokenType::Comma, ','),
                ';'     => $this->add(TokenType::Semicolon, ';'),
                '|'     => $this->add(TokenType::Pipe, '|'),
                ':'     => $this->add(TokenType::Colon, ':'),
                '.'     => $this->add(TokenType::Dot, '.'),
                default => throw new AntlersSyntaxException(
                    "Unexpected character '$ch' at position $this->pos in: $this->input",
                ),
            };
        }

        $this->tokens[] = new Token(TokenType::Eof, '', $this->pos);

        return $this->tokens;
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->length && ctype_space($this->input[$this->pos])) {
            $this->pos++;
        }
    }

    private function readNumber(): void
    {
        $start = $this->pos;

        if ($this->input[$this->pos] === '-') {
            $this->pos++;
        }

        while ($this->pos < $this->length && ctype_digit($this->input[$this->pos])) {
            $this->pos++;
        }

        if (
            $this->pos < $this->length
            && $this->input[$this->pos] === '.'
            && $this->pos + 1 < $this->length
            && ctype_digit($this->input[$this->pos + 1])
        ) {
            $this->pos++; // consume dot

            while ($this->pos < $this->length && ctype_digit($this->input[$this->pos])) {
                $this->pos++;
            }
        }

        $this->tokens[] = new Token(
            TokenType::Number,
            substr($this->input, $start, $this->pos - $start),
            $start,
        );
    }

    private function readString(string $quote): void
    {
        $start = $this->pos;

        $this->pos++; // skip opening quote

        $value = '';

        while ($this->pos < $this->length) {
            $ch = $this->input[$this->pos];

            if ($ch === '\\' && $this->pos + 1 < $this->length) {
                $next = $this->input[$this->pos + 1];

                $value .= match ($next) {
                    'n'     => "\n",
                    't'     => "\t",
                    'r'     => "\r",
                    '\\'    => '\\',
                    default => '\\' . $next,
                };

                $this->pos += 2;

                continue;
            }

            if ($ch === $quote) {
                $this->pos++;

                break;
            }

            $value .= $ch;

            $this->pos++;
        }

        $this->tokens[] = new Token(TokenType::String, $value, $start);
    }

    private function readIdentifier(): void
    {
        $start = $this->pos;

        while (
            $this->pos < $this->length
            && (ctype_alnum($this->input[$this->pos]) || $this->input[$this->pos] === '_')
        ) {
            $this->pos++;
        }

        $value = substr($this->input, $start, $this->pos - $start);

        $type = match (strtolower($value)) {
            'true'  => TokenType::True,
            'false' => TokenType::False,
            'null'  => TokenType::Null,
            'and'   => TokenType::And,
            'or'    => TokenType::Or,
            'not'   => TokenType::Not,
            'as'    => TokenType::As,
            default => TokenType::Identifier,
        };

        $this->tokens[] = new Token($type, $value, $start);
    }

    private function add(TokenType $type, string $value): void
    {
        $this->tokens[] = new Token($type, $value, $this->pos);

        $this->pos += strlen($value);
    }

    private function tryRead(string $str): bool
    {
        return substr($this->input, $this->pos, strlen($str)) === $str;
    }

    /**
     * Returns true if the last emitted token represents a "value" token,
     * used to distinguish unary minus from subtraction.
     */
    private function lastIsValue(): bool
    {
        if ($this->tokens === []) {
            return false;
        }

        $last = end($this->tokens);

        return $last->is(
            TokenType::Identifier,
            TokenType::Number,
            TokenType::String,
            TokenType::True,
            TokenType::False,
            TokenType::Null,
            TokenType::RParen,
            TokenType::RBracket,
        );
    }
}
