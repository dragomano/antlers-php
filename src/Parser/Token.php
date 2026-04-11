<?php

declare(strict_types=1);

namespace Bugo\Antlers\Parser;

use Stringable;

final readonly class Token implements Stringable
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $offset = 0,
    ) {}

    public function is(TokenType ...$types): bool
    {
        return in_array($this->type, $types, strict: true);
    }

    public function __toString(): string
    {
        return "[{$this->type->value}:$this->value]";
    }
}
