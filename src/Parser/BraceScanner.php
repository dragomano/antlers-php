<?php

declare(strict_types=1);

namespace Bugo\Antlers\Parser;

final class BraceScanner
{
    public static function closingBrace(string $input, int $open): ?int
    {
        $depth  = 1;
        $quote  = null;
        $length = strlen($input);

        for ($pos = $open + 1; $pos < $length; $pos++) {
            $ch = $input[$pos];

            if ($quote !== null) {
                if ($ch === '\\') {
                    $pos++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
            } elseif ($ch === '@' && $pos + 1 < $length && ($input[$pos + 1] === '{' || $input[$pos + 1] === '}')) {
                $pos++;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}' && --$depth === 0) {
                return $pos;
            }
        }

        return null;
    }
}
