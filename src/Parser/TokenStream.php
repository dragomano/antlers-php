<?php

declare(strict_types=1);

namespace Bugo\Antlers\Parser;

/**
 * A token list with a cursor, plus the position information errors need.
 *
 * Sub-expressions get their own stream instead of borrowing the parser's, so a
 * nested parse cannot leave the outer one reading a foreign token list.
 */
final class TokenStream
{
    private int $pos = 0;

    /**
     * @param list<Token> $tokens
     * @param string      $source   fragment the tokens came from, quoted back in errors
     * @param int         $baseLine template line the fragment starts on
     */
    public function __construct(
        private readonly array $tokens,
        public readonly string $source = '',
        public readonly int $baseLine = 1,
    ) {}

    public function peek(): Token
    {
        return $this->at($this->pos);
    }

    public function advance(): Token
    {
        $token = $this->at($this->pos);

        $this->pos++;

        return $token;
    }

    public function at(int $index): Token
    {
        return $this->tokens[$index] ?? $this->end();
    }

    public function position(): int
    {
        return $this->pos;
    }

    public function seek(int $position): void
    {
        $this->pos = $position;
    }

    /**
     * @return list<Token>
     */
    public function slice(int $from, int $length): array
    {
        return array_slice($this->tokens, $from, $length);
    }

    /**
     * Stand-in for a missing token, positioned at the last real one so errors
     * past the end of a fragment still report where that fragment was.
     */
    private function end(): Token
    {
        $last = $this->tokens === [] ? null : $this->tokens[count($this->tokens) - 1];

        return new Token(TokenType::Eof, '', $last->offset ?? 0, $last->line ?? $this->baseLine);
    }
}
