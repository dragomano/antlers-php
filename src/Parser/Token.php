<?php

declare(strict_types=1);

namespace Bugo\Antlers\Parser;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $offset = 0,
        public int $line = 0,
    ) {}

    public function is(TokenType ...$types): bool
    {
        return in_array($this->type, $types, strict: true);
    }
}
