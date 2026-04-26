<?php

declare(strict_types=1);

namespace Bugo\Antlers\Parser;

enum CollectionOperator: string
{
    case Merge   = 'merge';
    case Where   = 'where';
    case Take    = 'take';
    case Skip    = 'skip';
    case Pluck   = 'pluck';
    case OrderBy = 'orderby';
    case GroupBy = 'groupby';

    public static function tryFromToken(Token $token): ?self
    {
        if (! $token->is(TokenType::Identifier)) {
            return null;
        }

        return self::tryFrom(strtolower($token->value));
    }
}
